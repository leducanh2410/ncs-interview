import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFile, LuImage, LuPaperclip, LuSend, LuX } from 'react-icons/lu';
import { ActionIcon, Box, Group, Stack, Text, Textarea, Tooltip } from '@mantine/core';
import NotificationHelper from '@/helpers/notification.helper';
import classes from './chat.module.css';
import {
    CHAT_ATTACHMENT_ACCEPT,
    CHAT_ATTACHMENT_MAX_FILES,
    CHAT_ATTACHMENT_MAX_SIZE_KB,
    formatFileSize,
} from './helpers';

type MessageComposerProps = {
    onSend: (body: string, files: File[]) => Promise<void>;
    isSending: boolean;
    placeholder?: string;
    allowAttachments?: boolean;
};

const isImageFile = (file: File) => file.type.startsWith('image/');

/**
 * Shared message input bar. Owns the draft text and pending attachments, clears them on a
 * successful send, and restores them if the send rejects.
 */
const MessageComposer = ({ onSend, isSending, placeholder, allowAttachments = true }: MessageComposerProps) => {
    const { t } = useTranslation();
    const [body, setBody] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const fileInputRef = useRef<HTMLInputElement | null>(null);

    const addFiles = (incoming: File[]) => {
        if (incoming.length === 0) return;

        const maxBytes = CHAT_ATTACHMENT_MAX_SIZE_KB * 1024;
        const tooLarge = incoming.find((file) => file.size > maxBytes);
        if (tooLarge) {
            NotificationHelper.showError(
                t('"{{name}}" exceeds the {{size}} limit', {
                    name: tooLarge.name,
                    size: formatFileSize(maxBytes),
                }),
            );
            return;
        }

        setFiles((current) => {
            const next = [...current, ...incoming];
            if (next.length > CHAT_ATTACHMENT_MAX_FILES) {
                NotificationHelper.showError(
                    t('You can attach at most {{count}} files', { count: CHAT_ATTACHMENT_MAX_FILES }),
                );
                return next.slice(0, CHAT_ATTACHMENT_MAX_FILES);
            }
            return next;
        });
    };

    const onPickFiles = (event: React.ChangeEvent<HTMLInputElement>) => {
        addFiles(Array.from(event.target.files ?? []));
        // Reset so picking the same file again still fires onChange.
        event.target.value = '';
    };

    const removeFile = (index: number) => {
        setFiles((current) => current.filter((_, i) => i !== index));
    };

    const send = async () => {
        const trimmed = body.trim();
        if (!trimmed && files.length === 0) return;

        const sentFiles = files;
        setBody('');
        setFiles([]);
        try {
            await onSend(trimmed, sentFiles);
        } catch {
            setBody(trimmed);
            setFiles(sentFiles);
        }
    };

    const canSend = (body.trim().length > 0 || files.length > 0) && !isSending;

    return (
        <Box>
            {files.length > 0 && (
                <Stack gap={6} mb={6}>
                    {files.map((file, index) => {
                        const Icon = isImageFile(file) ? LuImage : LuFile;
                        return (
                            <Group key={`${file.name}-${index}`} gap={8} wrap="nowrap" className={classes.pendingFile}>
                                <Icon size={16} style={{ flexShrink: 0 }} />
                                <Text size="xs" truncate style={{ flex: 1, minWidth: 0 }}>
                                    {file.name}
                                </Text>
                                <Text size="xs" c="dimmed" style={{ flexShrink: 0 }}>
                                    {formatFileSize(file.size)}
                                </Text>
                                <ActionIcon
                                    variant="subtle"
                                    color="gray"
                                    size="sm"
                                    onClick={() => removeFile(index)}
                                    aria-label={t('Remove')}
                                >
                                    <LuX size={14} />
                                </ActionIcon>
                            </Group>
                        );
                    })}
                </Stack>
            )}
            <Box className={classes.inputBar}>
                {allowAttachments && (
                    <>
                        <input
                            ref={fileInputRef}
                            type="file"
                            multiple
                            accept={CHAT_ATTACHMENT_ACCEPT}
                            hidden
                            onChange={onPickFiles}
                        />
                        <Tooltip label={t('Attach files')} withArrow>
                            <ActionIcon
                                size="lg"
                                radius="xl"
                                variant="subtle"
                                color="gray"
                                onClick={() => fileInputRef.current?.click()}
                                aria-label={t('Attach files')}
                            >
                                <LuPaperclip size={18} />
                            </ActionIcon>
                        </Tooltip>
                    </>
                )}
                <Textarea
                    flex={1}
                    variant="unstyled"
                    autosize
                    minRows={1}
                    maxRows={4}
                    maxLength={5000}
                    placeholder={placeholder ?? t('Type a message')}
                    value={body}
                    onChange={(e) => setBody(e.currentTarget.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            send();
                        }
                    }}
                />
                <ActionIcon
                    size="lg"
                    radius="xl"
                    variant="filled"
                    onClick={send}
                    loading={isSending}
                    disabled={!canSend}
                    aria-label={t('Send')}
                >
                    <LuSend size={18} />
                </ActionIcon>
            </Box>
        </Box>
    );
};

export default MessageComposer;
