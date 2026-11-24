import { type ReactNode, useEffect, useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload, LuEye, LuFile, LuMessageCircle, LuPin, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import {
    ActionIcon,
    Anchor,
    Box,
    Button,
    Center,
    Group,
    Loader,
    Modal,
    ScrollArea,
    Stack,
    Text,
    Tooltip,
} from '@mantine/core';
import { useReducedMotion } from '@mantine/hooks';
import { ChatMessage, ChatMessageAttachment } from '@/types/models/chat';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import DAvatar from '@/components/DAvatar';
import classes from './chat.module.css';
import ChatEmptyState from './ChatEmptyState';
import { ChatImageGrid, ChatImageViewer } from './ChatImageGrid';
import { MessageReadReceipts, READ_RECEIPT_AVATAR_LIMIT, formatMessageTime, systemMessageText } from './helpers';
import { usePermissions } from '@/hooks/usePermission';

type MessageListProps = {
    messages: ChatMessage[];
    currentUserId: number;
    senderNameById: Map<number, string>;
    isAdmin?: boolean;
    canDeleteMessage?: boolean;
    senderStaffNoById?: Map<number, string | null>;
    hasMore?: boolean;
    isLoadingOlder?: boolean;
    onLoadOlder?: () => void;
    onPinMessage?: (messageId: number) => void;
    onDeleteMessage?: (messageId: number) => Promise<void>;
    pinnedMessageId?: number | null;
    // Reader avatars keyed by the message each reader last read; omitted where receipts are off.
    readReceipts?: MessageReadReceipts;
    onOpenReadReceipts?: (messageId: number) => void;
};

// Treat the user as "following" the conversation while their scroll sits within this
// many pixels of the bottom; only then does a new message pull the viewport down.
const FOLLOW_THRESHOLD_PX = 120;

// Trigger older-message loading once the viewport scrolls within this many pixels of the top.
const LOAD_OLDER_THRESHOLD_PX = 80;

const URL_REGEX = /https?:\/\/[^\s<>"']+/g;

// Strip trailing punctuation that is commonly appended after a URL in prose.
const trimUrlTrailing = (url: string) => url.replace(/[.,;:!?)>]+$/, '');

const renderMessageBody = (body: string, isMine: boolean) => {
    const parts: ReactNode[] = [];
    let lastIndex = 0;
    let match: RegExpExecArray | null;

    URL_REGEX.lastIndex = 0;
    while ((match = URL_REGEX.exec(body)) !== null) {
        if (match.index > lastIndex) {
            parts.push(body.slice(lastIndex, match.index));
        }
        const url = trimUrlTrailing(match[0]);
        const trailing = match[0].slice(url.length);
        parts.push(
            <a
                key={match.index}
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                className={`${classes.messageLink}${isMine ? ` ${classes.messageLinkMine}` : ''}`}
            >
                {url}
            </a>,
        );
        if (trailing) parts.push(trailing);
        lastIndex = match.index + match[0].length;
    }

    if (lastIndex < body.length) {
        parts.push(body.slice(lastIndex));
    }

    return parts;
};

const attachmentFilename = (attachment: ChatMessageAttachment) => {
    return attachment.filename || `attachment-${attachment.attachment_id}`;
};

const saveBlob = (blob: Blob, filename: string) => {
    const url = window.URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    window.setTimeout(() => window.URL.revokeObjectURL(url), 0);
};

type ViewerState = { attachments: ChatMessageAttachment[]; index: number };

const MessageList = ({
    messages,
    currentUserId,
    senderNameById,
    isAdmin = false,
    canDeleteMessage = false,
    senderStaffNoById,
    hasMore = false,
    isLoadingOlder = false,
    onLoadOlder,
    onPinMessage,
    onDeleteMessage,
    pinnedMessageId,
    readReceipts,
    onOpenReadReceipts,
}: MessageListProps) => {
    const { t } = useTranslation();
    const reduceMotion = useReducedMotion();
    const perms = usePermissions(['USER_LIST']);
    const viewportRef = useRef<HTMLDivElement | null>(null);
    const bottomRef = useRef<HTMLDivElement | null>(null);
    const isAtBottomRef = useRef(true);
    // Set just before requesting older messages so we can re-anchor the viewport once they prepend.
    const restoreScrollHeightRef = useRef<number | null>(null);
    const [downloadingAttachmentIds, setDownloadingAttachmentIds] = useState<Set<number>>(new Set());
    const [viewer, setViewer] = useState<ViewerState | null>(null);
    const [deletingMessageId, setDeletingMessageId] = useState<number | null>(null);
    const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);

    const handleScrollPositionChange = () => {
        const viewport = viewportRef.current;
        if (!viewport) return;
        const distanceFromBottom = viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight;
        isAtBottomRef.current = distanceFromBottom <= FOLLOW_THRESHOLD_PX;

        if (onLoadOlder && hasMore && !isLoadingOlder && viewport.scrollTop <= LOAD_OLDER_THRESHOLD_PX) {
            // Remember the current height so the prepend can keep the same message under the cursor.
            restoreScrollHeightRef.current = viewport.scrollHeight;
            onLoadOlder();
        }
    };

    // Re-anchor after older messages prepend: keep the previously-visible message in place instead
    // of jumping to the new top. Runs before paint to avoid a visible scroll flash.
    useLayoutEffect(() => {
        const viewport = viewportRef.current;
        if (!viewport || restoreScrollHeightRef.current === null) return;
        viewport.scrollTop = viewport.scrollHeight - restoreScrollHeightRef.current;
        restoreScrollHeightRef.current = null;
    }, [messages]);

    useEffect(() => {
        // A prepend (older messages) must not yank the viewport to the bottom.
        if (!isAtBottomRef.current || restoreScrollHeightRef.current !== null) return;
        bottomRef.current?.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'end' });
    }, [messages.length, reduceMotion]);

    const fetchAttachmentBlob = (attachment: ChatMessageAttachment): Promise<Blob> => {
        return ApiHelper.downloadBinary(
            CommonHelper.replaceUrlParam(ApiConfig.CHAT_ATTACHMENT_DOWNLOAD, {
                id: attachment.attachment_id,
            }),
        );
    };

    // Grid previews must be fetched with the bearer token (the routes sit behind auth:api), so we
    // cannot put the protected URL straight into <img src>. Prefer the lightweight thumbnail route;
    // fall back to the original endpoint when no thumbnail was generated.
    const fetchAttachmentThumbnail = (attachment: ChatMessageAttachment): Promise<Blob> => {
        const uri = attachment.thumbnail_url
            ? CommonHelper.replaceUrlParam(ApiConfig.CHAT_ATTACHMENT_THUMBNAIL, { id: attachment.attachment_id })
            : CommonHelper.replaceUrlParam(ApiConfig.CHAT_ATTACHMENT_DOWNLOAD, { id: attachment.attachment_id });

        return ApiHelper.downloadBinary(uri);
    };

    const downloadAttachment = async (attachment: ChatMessageAttachment) => {
        if (downloadingAttachmentIds.has(attachment.attachment_id)) {
            return;
        }

        setDownloadingAttachmentIds((current) => new Set(current).add(attachment.attachment_id));
        try {
            const blob = await fetchAttachmentBlob(attachment);
            saveBlob(blob, attachmentFilename(attachment));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not download attachment'));
        } finally {
            setDownloadingAttachmentIds((current) => {
                const next = new Set(current);
                next.delete(attachment.attachment_id);
                return next;
            });
        }
    };

    const renderSystemMessage = (message: ChatMessage) => (
        <Box key={message.id} className={classes.systemPill}>
            {systemMessageText(message, t)}
        </Box>
    );

    const renderFileAttachment = (attachment: ChatMessageAttachment, isMine: boolean) => {
        const filename = attachmentFilename(attachment);
        const isDownloading = downloadingAttachmentIds.has(attachment.attachment_id);

        return (
            <button
                key={attachment.attachment_id}
                type="button"
                disabled={isDownloading}
                aria-label={t('Download {{filename}}', { filename })}
                className={`${classes.attachmentCard}${isMine ? ` ${classes.attachmentCardMine}` : ''}`}
                onClick={() => downloadAttachment(attachment)}
            >
                <span className={classes.attachmentIcon}>
                    <LuFile size={16} />
                </span>
                <span className={classes.attachmentBody}>
                    <span className={classes.attachmentName}>{filename}</span>
                    <span className={classes.attachmentType}>{t('File')}</span>
                </span>
                <span className={classes.attachmentDownloadIcon}>
                    {isDownloading ? <Loader size="xs" /> : <LuDownload size={15} />}
                </span>
            </button>
        );
    };

    return (
        <>
            <ScrollArea
                className={classes.messages}
                py="md"
                offsetScrollbars
                viewportRef={viewportRef}
                onScrollPositionChange={handleScrollPositionChange}
            >
                {messages.length === 0 ? (
                    <ChatEmptyState
                        icon={<LuMessageCircle size={40} color="var(--mantine-color-gray-3)" />}
                        message={t('No messages yet')}
                        mih={240}
                    />
                ) : (
                    <Stack gap={4} pr="xs">
                        {isLoadingOlder && (
                            <Center py="xs">
                                <Loader size="xs" />
                            </Center>
                        )}
                        {messages.map((message, index) => {
                            const isSystem =
                                message.message_type === 'system' || (!message.sender_user_id && !message.sender_name);
                            if (isSystem) {
                                return renderSystemMessage(message);
                            }

                            const isMine = message.sender_user_id === currentUserId;
                            const senderName =
                                (message.sender_user_id != null
                                    ? senderNameById.get(message.sender_user_id)
                                    : undefined) ??
                                message.sender_name ??
                                t('Unknown user');
                            const senderStaffNo =
                                message.sender_user_id != null
                                    ? (senderStaffNoById?.get(message.sender_user_id) ?? null)
                                    : null;
                            const userDetailPath =
                                message.sender_user_id != null && perms.USER_LIST
                                    ? RouteHelper.getPath('ADMIN_USER_DETAIL', { id: message.sender_user_id })
                                    : null;
                            const prev = messages[index - 1];
                            const next = messages[index + 1];
                            const isFirstOfGroup = !prev || prev.sender_user_id !== message.sender_user_id;
                            const isLastOfGroup = !next || next.sender_user_id !== message.sender_user_id;
                            const attachments = message.attachments ?? [];
                            const isPinned = pinnedMessageId != null && message.id === pinnedMessageId;

                            const imageAttachments = attachments.filter((a) => a.type === 'image');
                            const fileAttachments = attachments.filter((a) => a.type !== 'image');
                            const hasOnlyImages =
                                imageAttachments.length > 0 && fileAttachments.length === 0 && !message.body;

                            const readers = readReceipts?.get(message.id) ?? [];

                            const avatar = (
                                <DAvatar
                                    size="sm"
                                    radius="xl"
                                    name={senderName}
                                    src={message.sender_profile_picture ?? undefined}
                                />
                            );

                            return (
                                <Box
                                    key={message.id}
                                    mt={isFirstOfGroup ? 'sm' : imageAttachments.length > 0 ? 6 : undefined}
                                    className={classes.messageWrapper}
                                >
                                    {!isMine && isFirstOfGroup && (
                                        <Text size="xs" c="dimmed" ml={34} mb={2}>
                                            {userDetailPath ? (
                                                <Anchor component={Link} href={userDetailPath} size="xs">
                                                    {senderName}
                                                    {senderStaffNo && ` (${senderStaffNo})`}
                                                </Anchor>
                                            ) : (
                                                <>
                                                    {senderName}
                                                    {senderStaffNo && (
                                                        <Text span c="dimmed" size="xs">
                                                            {' '}
                                                            ({senderStaffNo})
                                                        </Text>
                                                    )}
                                                </>
                                            )}
                                        </Text>
                                    )}
                                    <Box className={isMine ? `${classes.row} ${classes.rowMine}` : classes.row}>
                                        {!isMine &&
                                            (isLastOfGroup ? (
                                                userDetailPath ? (
                                                    <Link
                                                        href={userDetailPath}
                                                        style={{ flexShrink: 0, lineHeight: 0 }}
                                                        aria-label={senderName}
                                                    >
                                                        {avatar}
                                                    </Link>
                                                ) : (
                                                    avatar
                                                )
                                            ) : (
                                                <Box w={26} style={{ flexShrink: 0 }} />
                                            ))}
                                        <Box
                                            className={`${classes.bubble} ${isMine ? classes.bubbleMine : classes.bubbleOther}${hasOnlyImages ? ` ${classes.bubbleImageOnly}` : ''}`}
                                        >
                                            {message.body && (
                                                <Box className={classes.messageBody}>
                                                    {renderMessageBody(message.body, isMine)}
                                                </Box>
                                            )}
                                            {imageAttachments.length > 0 && (
                                                <Box mt={message.body ? 8 : 0}>
                                                    <ChatImageGrid
                                                        attachments={imageAttachments}
                                                        onFetchThumbnail={fetchAttachmentThumbnail}
                                                        onView={(i) =>
                                                            setViewer({ attachments: imageAttachments, index: i })
                                                        }
                                                    />
                                                </Box>
                                            )}
                                            {fileAttachments.length > 0 && (
                                                <Stack gap={6} mt={message.body || imageAttachments.length > 0 ? 8 : 0}>
                                                    {fileAttachments.map((attachment) =>
                                                        renderFileAttachment(attachment, isMine),
                                                    )}
                                                </Stack>
                                            )}
                                        </Box>
                                        {isPinned && (
                                            <Tooltip label={t('Pinned message')} withArrow>
                                                <Box className={classes.pinnedIndicator}>
                                                    <LuPin size={12} />
                                                </Box>
                                            </Tooltip>
                                        )}
                                        {(isAdmin && onPinMessage && !isPinned) ||
                                        (canDeleteMessage && onDeleteMessage) ||
                                        onOpenReadReceipts ? (
                                            <Box className={classes.messageActions}>
                                                {onOpenReadReceipts && (
                                                    <Tooltip label={t('Message read status')} withArrow>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            color="gray"
                                                            size="xs"
                                                            aria-label={t('Message read status')}
                                                            onClick={() => onOpenReadReceipts(message.id)}
                                                        >
                                                            <LuEye size={12} />
                                                        </ActionIcon>
                                                    </Tooltip>
                                                )}
                                                {isAdmin && onPinMessage && !isPinned && (
                                                    <Tooltip label={t('Pin message')} withArrow>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            color="gray"
                                                            size="xs"
                                                            aria-label={t('Pin message')}
                                                            onClick={() => onPinMessage(message.id)}
                                                        >
                                                            <LuPin size={12} />
                                                        </ActionIcon>
                                                    </Tooltip>
                                                )}
                                                {canDeleteMessage && onDeleteMessage && (
                                                    <Tooltip label={t('Delete message')} withArrow>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            color="red"
                                                            size="xs"
                                                            loading={deletingMessageId === message.id}
                                                            aria-label={t('Delete message')}
                                                            onClick={() => setPendingDeleteId(message.id)}
                                                        >
                                                            <LuTrash2 size={12} />
                                                        </ActionIcon>
                                                    </Tooltip>
                                                )}
                                            </Box>
                                        ) : null}
                                    </Box>
                                    {isLastOfGroup && (
                                        <Text
                                            size="xs"
                                            c="dimmed"
                                            ta={isMine ? 'right' : 'left'}
                                            ml={isMine ? 0 : 34}
                                            mt={2}
                                        >
                                            {formatMessageTime(message.created_at)}
                                        </Text>
                                    )}
                                    {readers.length > 0 && (
                                        <Box
                                            component="button"
                                            type="button"
                                            className={`${classes.readReceipts}${isMine ? ` ${classes.readReceiptsMine}` : ''}`}
                                            ml={isMine ? 0 : 34}
                                            onClick={() => onOpenReadReceipts?.(message.id)}
                                            aria-label={t('Seen by {{count}} people', { count: readers.length })}
                                        >
                                            {readers.slice(0, READ_RECEIPT_AVATAR_LIMIT).map((reader) => (
                                                <Tooltip
                                                    key={reader.user_id}
                                                    label={reader.name ?? ''}
                                                    withArrow
                                                    disabled={!reader.name}
                                                >
                                                    <Box className={classes.readReceiptAvatar}>
                                                        <DAvatar
                                                            size={16}
                                                            radius="xl"
                                                            name={reader.name ?? ''}
                                                            src={reader.profile_picture ?? undefined}
                                                        />
                                                    </Box>
                                                </Tooltip>
                                            ))}
                                            {readers.length > READ_RECEIPT_AVATAR_LIMIT && (
                                                <Text span className={classes.readReceiptMore}>
                                                    +{readers.length - READ_RECEIPT_AVATAR_LIMIT}
                                                </Text>
                                            )}
                                        </Box>
                                    )}
                                </Box>
                            );
                        })}
                        <div ref={bottomRef} />
                    </Stack>
                )}
            </ScrollArea>

            <Modal
                opened={pendingDeleteId !== null}
                onClose={() => {
                    if (deletingMessageId === null) {
                        setPendingDeleteId(null);
                    }
                }}
                title={t('Delete message')}
                centered
                size="sm"
            >
                <Text size="sm">{t('This message will be permanently deleted and cannot be recovered.')}</Text>
                <Group justify="flex-end" mt="md">
                    <Button
                        variant="default"
                        disabled={deletingMessageId !== null}
                        onClick={() => setPendingDeleteId(null)}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        color="red"
                        loading={deletingMessageId !== null}
                        onClick={async () => {
                            if (pendingDeleteId === null || !onDeleteMessage) return;
                            const messageId = pendingDeleteId;
                            setDeletingMessageId(messageId);
                            try {
                                await onDeleteMessage(messageId);
                                setPendingDeleteId(null);
                            } catch {
                                // Error notification is handled by the owner; keep the dialog open for retry.
                            } finally {
                                setDeletingMessageId(null);
                            }
                        }}
                    >
                        {t('Delete')}
                    </Button>
                </Group>
            </Modal>
            {viewer && (
                <ChatImageViewer
                    attachments={viewer.attachments}
                    initialIndex={viewer.index}
                    onClose={() => setViewer(null)}
                    onDownload={downloadAttachment}
                    onFetchImage={fetchAttachmentBlob}
                />
            )}
        </>
    );
};

export default MessageList;
