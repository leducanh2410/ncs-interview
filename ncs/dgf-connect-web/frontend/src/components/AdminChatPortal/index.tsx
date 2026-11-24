import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuArrowLeft,
    LuEllipsisVertical,
    LuLock,
    LuLockOpen,
    LuMessageCircle,
    LuMessageSquareOff,
    LuPencil,
    LuPlus,
    LuSearch,
    LuSearchX,
    LuSettings,
    LuTrash2,
    LuUsers,
    LuX,
} from 'react-icons/lu';
import {
    ActionIcon,
    Badge,
    Box,
    Button,
    Center,
    Divider,
    Drawer,
    Group,
    Loader,
    Menu,
    Modal,
    Paper,
    ScrollArea,
    SegmentedControl,
    Select,
    Stack,
    Switch,
    Text,
    TextInput,
    UnstyledButton,
} from '@mantine/core';
import { useDebouncedValue, useDisclosure } from '@mantine/hooks';
import { useQueryClient } from '@tanstack/react-query';
import { useAtom, useAtomValue } from 'jotai';
import { v4 as uuidv4 } from 'uuid';
import { ApiResponse } from '@/types/api';
import {
    ChatConversationDetail,
    ChatConversationListItem,
    ChatConversationType,
    ChatMessage,
    ChatParticipant,
    CreateChatConversationPayload,
} from '@/types/models/chat';
import { User } from '@/types/models/user';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import { RoleNameEnum } from '@/enums/role_name.enum';
import { ApiError } from '@/helpers/api.helper';
import { getChatEcho } from '@/helpers/chat_echo.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RoleHelper from '@/helpers/role.helper';
import DAvatar from '@/components/DAvatar';
import DUserSelectModal from '@/components/DUserSelectModal';
import ChatEmptyState from '@/components/chat/ChatEmptyState';
import ChatErrorState from '@/components/chat/ChatErrorState';
import CloseConversationModal from '@/components/chat/CloseConversationModal';
import EmployeePermissionsModal from '@/components/chat/EmployeePermissionsModal';
import MessageComposer from '@/components/chat/MessageComposer';
import MessageList from '@/components/chat/MessageList';
import ParticipantsDrawer from '@/components/chat/ParticipantsDrawer';
import PinnedMessageBanner from '@/components/chat/PinnedMessageBanner';
import ReadReceiptsModal from '@/components/chat/ReadReceiptsModal';
import {
    ADMIN_CHAT_CONVERSATIONS_QUERY_KEY,
    ADMIN_CHAT_CONVERSATION_QUERY_KEY,
    ADMIN_CHAT_MESSAGES_QUERY_KEY,
    ADMIN_CHAT_MESSAGE_RECEIPTS_QUERY_KEY,
    ChatConversationStatusFilter,
    fetchAdminChatMessages,
    useAdminChatConversationQuery,
    useAdminChatConversationsQuery,
    useAdminChatMessagesQuery,
    useCreateAdminChatConversationMutation,
    useDeleteAdminChatConversationMutation,
    useDeleteAdminChatMessageMutation,
    useMarkAdminChatReadMutation,
    usePinAdminChatMessageMutation,
    useRenameAdminChatConversationMutation,
    useReopenAdminChatConversationMutation,
    useSendAdminChatMessageMutation,
    useUnpinAdminChatMessageMutation,
} from '@/components/chat/api';
import chatClasses from '@/components/chat/chat.module.css';
import {
    buildReadReceipts,
    canManagePinnedMessage,
    conversationTitle,
    conversationTypeBadgeColor,
    conversationTypeLabel,
    formatChatTime,
    messagePreview,
    uniqueMessages,
} from '@/components/chat/helpers';
import { authAtom, chatIntentAtom } from '@/pages/global.state';
import classes from './index.module.css';
import { useGetGroupsQuery } from '@/pages/admin/group/api';
import { useGetActiveShiftsQuery } from '@/pages/manage/shift/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

type ConversationListProps = {
    conversations: ChatConversationListItem[];
    search: string;
    onSearchChange: (value: string) => void;
    statusFilter: ChatConversationStatusFilter;
    onStatusFilterChange: (value: ChatConversationStatusFilter) => void;
    selectedConversationId: number | null;
    isLoading: boolean;
    isError: boolean;
    canCreate: boolean;
    onSelect: (id: number) => void;
    onCreate: () => void;
    onRetry: () => void;
    onClose: () => void;
};

const ConversationList = ({
    conversations,
    search,
    onSearchChange,
    statusFilter,
    onStatusFilterChange,
    selectedConversationId,
    isLoading,
    isError,
    canCreate,
    onSelect,
    onCreate,
    onRetry,
    onClose,
}: ConversationListProps) => {
    const { t } = useTranslation();

    return (
        <Box className={classes.panel}>
            <Box className={classes.panelHeader}>
                <Text fw={700} flex={1}>
                    {t('Chats')}
                </Text>
                {canCreate && (
                    <Button size="xs" leftSection={<LuPlus size={14} />} onClick={onCreate}>
                        {t('New chat')}
                    </Button>
                )}
                <ActionIcon variant="subtle" color="gray" onClick={onClose} aria-label={t('Close')}>
                    <LuX size={18} />
                </ActionIcon>
            </Box>
            <TextInput
                mt="xs"
                size="xs"
                value={search}
                onChange={(e) => onSearchChange(e.currentTarget.value)}
                placeholder={t('Search by name or employee code')}
                aria-label={t('Search by name or employee code')}
                leftSection={<LuSearch size={14} />}
                rightSection={
                    search ? (
                        <ActionIcon
                            variant="subtle"
                            color="gray"
                            size="sm"
                            onClick={() => onSearchChange('')}
                            aria-label={t('Clear search')}
                        >
                            <LuX size={14} />
                        </ActionIcon>
                    ) : null
                }
            />
            <SegmentedControl
                mt="xs"
                size="xs"
                fullWidth
                value={statusFilter}
                onChange={(value) => onStatusFilterChange(value as ChatConversationStatusFilter)}
                data={[
                    { value: 'open', label: t('Open') },
                    { value: 'closed', label: t('Closed') },
                    { value: 'all', label: t('All') },
                ]}
            />
            <ScrollArea className={classes.scroll} pt="xs">
                {isLoading ? (
                    <Center py="xl">
                        <Loader />
                    </Center>
                ) : isError ? (
                    <ChatErrorState message={t("Couldn't load chats")} onRetry={onRetry} />
                ) : conversations.length === 0 ? (
                    search ? (
                        <ChatEmptyState
                            icon={<LuSearchX size={40} color="var(--mantine-color-gray-3)" />}
                            message={t('No chats match your search')}
                        />
                    ) : (
                        <ChatEmptyState
                            icon={<LuMessageCircle size={40} color="var(--mantine-color-gray-3)" />}
                            message={t('No chats yet')}
                        />
                    )
                ) : (
                    <Stack gap={2}>
                        {conversations.map((conversation) => {
                            const title = conversationTitle(conversation, t);
                            const isActive = conversation.id === selectedConversationId;
                            return (
                                <Box
                                    key={conversation.id}
                                    role="button"
                                    tabIndex={0}
                                    aria-pressed={isActive}
                                    className={`${classes.convItem}${isActive ? ` ${classes.convItemActive}` : ''}`}
                                    onClick={() => onSelect(conversation.id)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' || e.key === ' ') {
                                            e.preventDefault();
                                            onSelect(conversation.id);
                                        }
                                    }}
                                >
                                    <Group gap={10} wrap="nowrap" align="center">
                                        <DAvatar size="md" radius="xl" name={title} />
                                        <Box className={classes.convBody}>
                                            <Group gap={6} wrap="nowrap">
                                                <Text fw={600} size="sm" truncate>
                                                    {title}
                                                </Text>
                                                {conversation.system_key != null ? (
                                                    <Badge
                                                        size="xs"
                                                        variant="light"
                                                        color="grape"
                                                        style={{ flexShrink: 0 }}
                                                    >
                                                        {t('Internal')}
                                                    </Badge>
                                                ) : (
                                                    conversation.type !== 'admin_group' && (
                                                        <Badge
                                                            size="xs"
                                                            variant="light"
                                                            color={conversationTypeBadgeColor(conversation.type)}
                                                            style={{ flexShrink: 0 }}
                                                        >
                                                            {conversationTypeLabel(conversation.type, t)}
                                                        </Badge>
                                                    )
                                                )}
                                                {conversation.status === 'closed' && (
                                                    <Badge
                                                        size="xs"
                                                        variant="light"
                                                        color="orange"
                                                        style={{ flexShrink: 0 }}
                                                    >
                                                        {t('Closed')}
                                                    </Badge>
                                                )}
                                            </Group>
                                            <Group gap={4} wrap="nowrap">
                                                {conversation.last_message?.sender_name && (
                                                    <DAvatar
                                                        size={16}
                                                        radius="xl"
                                                        name={conversation.last_message.sender_name}
                                                        src={
                                                            conversation.last_message.sender_profile_picture ??
                                                            undefined
                                                        }
                                                        style={{ flexShrink: 0 }}
                                                    />
                                                )}
                                                <Text
                                                    size="xs"
                                                    c={conversation.unread_count > 0 ? 'dark.4' : 'dimmed'}
                                                    fw={conversation.unread_count > 0 ? 600 : undefined}
                                                    truncate
                                                >
                                                    {messagePreview(conversation.last_message, t) ||
                                                        t('No messages yet')}
                                                </Text>
                                            </Group>
                                        </Box>
                                        <Box className={classes.convMeta}>
                                            <Text size="xs" c="dimmed">
                                                {formatChatTime(conversation.last_message_at)}
                                            </Text>
                                            {conversation.unread_count > 0 && (
                                                <Badge size="sm" circle color="red" variant="filled">
                                                    {conversation.unread_count}
                                                </Badge>
                                            )}
                                        </Box>
                                    </Group>
                                </Box>
                            );
                        })}
                    </Stack>
                )}
            </ScrollArea>
        </Box>
    );
};

type CreateChatPanelProps = {
    onCancel: () => void;
    onCreated: (conversationId: number) => void;
};

const CreateChatPanel = ({ onCancel, onCreated }: CreateChatPanelProps) => {
    const { t } = useTranslation();
    const [type, setType] = useState<Exclude<ChatConversationType, 'support' | 'guest_support'>>('admin_group');
    const [title, setTitle] = useState('');
    const [selectedUsers, setSelectedUsers] = useState<User[]>([]);
    const [shiftId, setShiftId] = useState<string | null>(null);
    const [userGroupId, setUserGroupId] = useState<string | null>(null);
    const [canMessage, setCanMessage] = useState(true);
    const [canImage, setCanImage] = useState(true);
    const [canFile, setCanFile] = useState(true);
    const [userModalOpened, userModal] = useDisclosure(false);
    const createMutation = useCreateAdminChatConversationMutation();
    const [chatIntent, setChatIntent] = useAtom(chatIntentAtom);

    // Seeds the form once from a `create-direct` intent (the message shortcut on the shift
    // detail row), then clears the atom so reopening the panel manually does not replay it.
    useEffect(() => {
        if (chatIntent?.kind !== 'create-direct') {
            return;
        }
        setType('admin_group');
        setSelectedUsers([chatIntent.user]);
        setTitle(chatIntent.defaultTitle);
        setChatIntent(null);
    }, []);

    const activeShiftsQuery = useGetActiveShiftsQuery();
    const groupsQuery = useGetGroupsQuery({ per_page: '200' }, type === 'user_group');

    const shiftOptions = useMemo(
        () =>
            (activeShiftsQuery.data?.data ?? []).map((shift) => ({
                value: String(shift.id),
                label: `${shift.workplace?.name ?? t('Shift')} · ${shift.date} ${shift.workplace_shift?.start_time ?? ''}–${shift.workplace_shift?.end_time ?? ''}`,
            })),
        [activeShiftsQuery.data?.data, t],
    );

    const groupOptions = useMemo(
        () =>
            (groupsQuery.data?.data?.data ?? []).map((group) => ({
                value: String(group.id),
                label: `${getGroupDisplayName(group)} (${group.member_count})`,
            })),
        [groupsQuery.data?.data?.data],
    );

    const buildPayload = (): CreateChatConversationPayload | null => {
        const base = {
            title: title.trim() || null,
            employee_can_send_message: canMessage,
            employee_can_send_image: canImage,
            employee_can_send_file: canFile,
        };

        if (type === 'admin_group') {
            if (selectedUsers.length === 0) {
                NotificationHelper.showError(t('Please select at least one user'));
                return null;
            }
            return { type, ...base, participant_user_ids: selectedUsers.map((user) => user.id) };
        }

        if (type === 'shift_group') {
            if (!shiftId) {
                NotificationHelper.showError(t('Please select a shift'));
                return null;
            }
            return { type, ...base, shift_id: Number(shiftId) };
        }

        if (!userGroupId) {
            NotificationHelper.showError(t('Please select a user group'));
            return null;
        }
        return { type, ...base, user_group_id: Number(userGroupId) };
    };

    const onSubmit = async () => {
        const payload = buildPayload();
        if (!payload) return;

        try {
            const response = await createMutation.mutateAsync(payload);
            const conversationId = response.data?.id;
            if (conversationId) {
                NotificationHelper.showSuccess(t('Successfully created chat'));
                onCreated(conversationId);
            }
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not create chat'));
        }
    };

    const removeUser = (userId: number) => {
        setSelectedUsers((current) => current.filter((user) => user.id !== userId));
    };

    return (
        <Box className={classes.panel}>
            <Box className={classes.panelHeader}>
                <ActionIcon variant="subtle" color="gray" onClick={onCancel} aria-label={t('Back')}>
                    <LuArrowLeft size={18} />
                </ActionIcon>
                <Text fw={700}>{t('Create chat')}</Text>
            </Box>
            <ScrollArea className={classes.scroll} pt="md">
                <Stack gap="md">
                    <Box>
                        <Text size="sm" fw={500} mb={6}>
                            {t('Chat type')}
                        </Text>
                        <SegmentedControl
                            fullWidth
                            value={type}
                            onChange={(value) =>
                                setType(value as Exclude<ChatConversationType, 'support' | 'guest_support'>)
                            }
                            data={[
                                { value: 'admin_group', label: t('Group') },
                                { value: 'shift_group', label: t('Shift') },
                                { value: 'user_group', label: t('User group') },
                            ]}
                        />
                    </Box>

                    <TextInput
                        label={t('Chat title')}
                        placeholder={
                            type === 'shift_group'
                                ? t('Leave blank to use the shift name')
                                : type === 'user_group'
                                  ? t('Leave blank to use the user group name')
                                  : t('Optional')
                        }
                        value={title}
                        onChange={(e) => setTitle(e.currentTarget.value)}
                        maxLength={255}
                    />

                    {type === 'admin_group' && (
                        <Box>
                            <Group justify="space-between" mb="xs">
                                <Text size="sm" fw={500}>
                                    {t('Selected users')}
                                </Text>
                                <Badge variant="light">{selectedUsers.length}</Badge>
                            </Group>
                            <Paper withBorder radius="md" p="sm" mih={120}>
                                {selectedUsers.length === 0 ? (
                                    <Center h={96}>
                                        <Text size="sm" c="dimmed">
                                            {t('No users selected')}
                                        </Text>
                                    </Center>
                                ) : (
                                    <Stack gap={6}>
                                        {selectedUsers.map((user) => (
                                            <Group key={user.id} gap="xs" wrap="nowrap">
                                                <DAvatar
                                                    size="sm"
                                                    radius="xl"
                                                    name={user.name}
                                                    src={user.profile_picture}
                                                />
                                                <Box style={{ minWidth: 0, flex: 1 }}>
                                                    <Text size="sm" truncate>
                                                        {user.name}
                                                    </Text>
                                                    <Text size="xs" c="dimmed" truncate>
                                                        {user.staff_no || '-'}
                                                    </Text>
                                                </Box>
                                                <ActionIcon
                                                    variant="subtle"
                                                    color="gray"
                                                    size="sm"
                                                    onClick={() => removeUser(user.id)}
                                                    aria-label={t('Remove')}
                                                >
                                                    <LuX size={14} />
                                                </ActionIcon>
                                            </Group>
                                        ))}
                                    </Stack>
                                )}
                            </Paper>
                            <Button
                                mt="sm"
                                variant="default"
                                leftSection={<LuUsers size={14} />}
                                onClick={userModal.open}
                            >
                                {t('Select users')}
                            </Button>
                        </Box>
                    )}

                    {type === 'shift_group' && (
                        <Select
                            label={t('Shift')}
                            placeholder={
                                activeShiftsQuery.isLoading ? t('Loading...') : t('Select an active or upcoming shift')
                            }
                            data={shiftOptions}
                            value={shiftId}
                            onChange={setShiftId}
                            searchable
                            nothingFoundMessage={t('No shifts found')}
                            disabled={activeShiftsQuery.isLoading}
                        />
                    )}

                    {type === 'user_group' && (
                        <Select
                            label={t('User group')}
                            placeholder={groupsQuery.isLoading ? t('Loading...') : t('Select a user group')}
                            data={groupOptions}
                            value={userGroupId}
                            onChange={setUserGroupId}
                            searchable
                            nothingFoundMessage={t('No user groups found')}
                            disabled={groupsQuery.isLoading}
                        />
                    )}

                    <Divider label={t('Employee permissions')} labelPosition="left" />
                    <Stack gap="xs">
                        <Switch
                            checked={canMessage}
                            onChange={(e) => setCanMessage(e.currentTarget.checked)}
                            label={t('Send text messages')}
                        />
                        <Switch
                            checked={canImage}
                            onChange={(e) => setCanImage(e.currentTarget.checked)}
                            label={t('Send images')}
                        />
                        <Switch
                            checked={canFile}
                            onChange={(e) => setCanFile(e.currentTarget.checked)}
                            label={t('Send files')}
                        />
                    </Stack>
                </Stack>
            </ScrollArea>
            <Group justify="end" pt="md">
                <Button variant="filled" color="gray" onClick={onCancel}>
                    {t('Cancel')}
                </Button>
                <Button
                    variant="filled"
                    color="teal"
                    leftSection={<LuPlus size={16} />}
                    loading={createMutation.isPending}
                    onClick={onSubmit}
                >
                    {t('Create')}
                </Button>
            </Group>
            <DUserSelectModal
                isOpen={userModalOpened}
                onClose={userModal.close}
                onConfirm={setSelectedUsers}
                title={t('Select users')}
                initialSelectedUsers={selectedUsers}
                confirmButtonLabel={t('Save')}
            />
        </Box>
    );
};

type ChatWindowProps = {
    conversationId: number | null;
    currentUserId: number;
    canEdit: boolean;
    canDeleteMessagePermission: boolean;
    canDeleteConversationPermission: boolean;
    isGlobalAdmin: boolean;
    isPortalOpen: boolean;
    realtimeMessages: ChatMessage[];
    deletedMessageIds: Set<number>;
    isDeletingConversation: boolean;
    onLocalMessage: (message: ChatMessage) => void;
    onMessageDeleted: (messageId: number) => void;
    onBack: () => void;
    onDeleteConversation: (conversationId: number) => Promise<void>;
};

const ChatWindow = ({
    conversationId,
    currentUserId,
    canEdit,
    canDeleteMessagePermission,
    canDeleteConversationPermission,
    isGlobalAdmin,
    isPortalOpen,
    realtimeMessages,
    deletedMessageIds,
    isDeletingConversation,
    onLocalMessage,
    onMessageDeleted,
    onBack,
    onDeleteConversation,
}: ChatWindowProps) => {
    const { t } = useTranslation();
    const lastMarkedReadId = useRef<number | null>(null);
    const detailQuery = useAdminChatConversationQuery(conversationId, isPortalOpen);
    const messagesQuery = useAdminChatMessagesQuery(conversationId, isPortalOpen);
    const sendMutation = useSendAdminChatMessageMutation(conversationId);
    const markReadMutation = useMarkAdminChatReadMutation(conversationId);
    const reopenMutation = useReopenAdminChatConversationMutation(conversationId);
    const pinMutation = usePinAdminChatMessageMutation(conversationId);
    const unpinMutation = useUnpinAdminChatMessageMutation(conversationId);
    const deleteMessageMutation = useDeleteAdminChatMessageMutation(conversationId);

    const [olderMessages, setOlderMessages] = useState<ChatMessage[]>([]);
    const [hasMoreOlder, setHasMoreOlder] = useState(false);
    const [isLoadingOlder, setIsLoadingOlder] = useState(false);
    const [rosterOpened, roster] = useDisclosure(false);
    const [closeOpened, closeModal] = useDisclosure(false);
    const [permissionsOpened, permissionsModal] = useDisclosure(false);
    const [deleteConversationOpened, deleteConversationModal] = useDisclosure(false);
    const [receiptsMessageId, setReceiptsMessageId] = useState<number | null>(null);
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleDraft, setTitleDraft] = useState('');
    const renameMutation = useRenameAdminChatConversationMutation(conversationId);
    const renameSubmittedRef = useRef(false);

    const detail = detailQuery.data?.data ?? null;
    const participants = detail?.participants ?? [];
    const isActiveParticipant = participants.some((participant) => participant.user_id === currentUserId);
    // Deleting requires the dedicated permission plus being an active participant (mirrors the BE:
    // route middleware checks chat.delete_message/chat.delete_conversation, the action checks membership).
    const canDeleteMessage = canDeleteMessagePermission && isActiveParticipant;
    // The internal admin group is owned by the system: its roster follows the manager.auth
    // permission and the backend refuses every hand edit, so the UI hides those actions instead
    // of offering buttons that can only fail. Renaming is the one exception, for global admins.
    const isSystemConversation = detail?.system_key != null;
    const canDeleteConversation = canDeleteConversationPermission && isActiveParticipant && !isSystemConversation;
    const isClosed = detail?.status === 'closed';
    const isSupport = detail?.type === 'support';
    const isGroupChat =
        detail?.type === 'admin_group' || detail?.type === 'shift_group' || detail?.type === 'user_group';
    const selfRole = detail?.self?.role ?? null;
    const isAdmin = selfRole === 'admin';
    const isDeputy = selfRole === 'deputy';
    const isPrivileged = isAdmin || isDeputy;

    // Admins can edit manual-group rosters and scoped group extras; the drawer narrows scoped removals to extras.
    const canManageParticipants = canEdit && isAdmin && isGroupChat && !isClosed && !isSystemConversation;
    // Deputy assignment is supported by BE on all group types.
    const canManageDeputy = canEdit && isAdmin && isGroupChat && !isClosed && !isSystemConversation;
    // Admin-only: update employee permissions and manage roster.
    const canManageSettings = canEdit && isAdmin && !isSupport && !isSystemConversation;
    // Admin (needs ChatEdit) or deputy (role-based, no ChatEdit required): lock/unlock group chats.
    const canCloseReopen = ((canEdit && isAdmin) || (isDeputy && isGroupChat)) && !isSystemConversation;
    // A shift group whose shift was deleted stays closed for good; the BE refuses the reopen too.
    const isShiftDeleted = detail?.type === 'shift_group' && detail.shift_id === null;
    const canReopen = canCloseReopen && !isShiftDeleted;
    const hasLifecycleAction = isClosed ? canReopen : canCloseReopen;
    // Admin or deputy in group chats can write even when closed.
    const canWriteWhenClosed = isGroupChat && isPrivileged;
    // Only group leaders (role=admin) with ChatEdit may pin/unpin, matching the BE.
    const canPin = canManagePinnedMessage(canEdit, isAdmin, isGroupChat);

    const senderNameById = useMemo(() => {
        const map = new Map<number, string>();
        participants.forEach((participant: ChatParticipant) => {
            if (participant.name) {
                map.set(participant.user_id, participant.name);
            }
        });
        return map;
    }, [participants]);

    const senderStaffNoById = useMemo(() => {
        const map = new Map<number, string | null>();
        participants.forEach((participant: ChatParticipant) => {
            map.set(participant.user_id, participant.staff_no);
        });
        return map;
    }, [participants]);

    const messages = useMemo(
        () =>
            uniqueMessages([
                ...olderMessages,
                ...(messagesQuery.data?.data?.messages ?? []),
                ...realtimeMessages,
            ]).filter((message) => !deletedMessageIds.has(message.id)),
        [deletedMessageIds, olderMessages, messagesQuery.data?.data?.messages, realtimeMessages],
    );

    // Who has read up to which message, derived from the participant read markers the admin
    // conversation detail carries. Recomputed as messages arrive or a read event updates a marker.
    const readReceipts = useMemo(
        () => buildReadReceipts(messages, participants, currentUserId),
        [messages, participants, currentUserId],
    );

    // Reset backscroll state whenever the open conversation changes.
    useEffect(() => {
        lastMarkedReadId.current = null;
        setReceiptsMessageId(null);
        setOlderMessages([]);
        setHasMoreOlder(false);
        setIsLoadingOlder(false);
    }, [conversationId]);

    // Seed the "older messages exist" flag from the first (newest) page response.
    useEffect(() => {
        const data = messagesQuery.data?.data;
        if (data && olderMessages.length === 0) {
            setHasMoreOlder(data.has_more);
        }
    }, [messagesQuery.data?.data, olderMessages.length]);

    const handlePinMessage = useCallback(
        async (messageId: number) => {
            try {
                await pinMutation.mutateAsync(messageId);
            } catch (e) {
                console.error(e);
                NotificationHelper.showError(t('error|Could not pin message'));
            }
        },
        [pinMutation, t],
    );

    const handleUnpin = useCallback(async () => {
        try {
            await unpinMutation.mutateAsync();
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not unpin message'));
        }
    }, [unpinMutation, t]);

    const handleDeleteMessage = useCallback(
        async (messageId: number) => {
            try {
                await deleteMessageMutation.mutateAsync(messageId);
                onMessageDeleted(messageId);
            } catch (e) {
                console.error(e);
                NotificationHelper.showError(t('error|Could not delete message'));
                throw e;
            }
        },
        [deleteMessageMutation, onMessageDeleted, t],
    );

    const loadOlder = useCallback(async () => {
        const oldestId = messages[0]?.id;
        if (!conversationId || !oldestId || isLoadingOlder || !hasMoreOlder) {
            return;
        }
        setIsLoadingOlder(true);
        try {
            const response = await fetchAdminChatMessages(conversationId, { before_id: oldestId, limit: 50 });
            const older = response.data?.messages ?? [];
            setOlderMessages((current) => uniqueMessages([...older, ...current]));
            setHasMoreOlder(response.data?.has_more ?? false);
        } catch (e) {
            console.error(e);
        } finally {
            setIsLoadingOlder(false);
        }
    }, [conversationId, messages, isLoadingOlder, hasMoreOlder]);

    useEffect(() => {
        const newestMessageId = messages[messages.length - 1]?.id;
        if (!conversationId || !newestMessageId || lastMarkedReadId.current === newestMessageId) {
            return;
        }
        lastMarkedReadId.current = newestMessageId;
        markReadMutation.mutate(newestMessageId);
    }, [conversationId, markReadMutation, messages]);

    const sendMessage = async (trimmed: string, files: File[]) => {
        if (!conversationId) return;

        try {
            const response = await sendMutation.mutateAsync({
                body: trimmed || undefined,
                client_message_id: uuidv4(),
                files,
            });
            if (response.data) {
                onLocalMessage(response.data);
                const maskedKeywords = response.data.masked_keywords ?? [];
                if (maskedKeywords.length > 0) {
                    NotificationHelper.showInfo(
                        t('The following banned keywords were hidden: {{keywords}}', {
                            keywords: maskedKeywords.join(', '),
                        }),
                    );
                }
            }
        } catch (e) {
            console.error(e);
            if (e instanceof ApiError && e.message === ErrorCodeEnum.CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD.value) {
                const keywords = Array.isArray(e.data?.keywords) ? (e.data.keywords as string[]) : [];
                NotificationHelper.showError(
                    keywords.length > 0
                        ? t('error|Message blocked by banned keyword(s): {{keywords}}', {
                              keywords: keywords.join(', '),
                          })
                        : t('error|Message contains a banned keyword and cannot be sent'),
                );
            } else {
                NotificationHelper.showError(t('error|Could not send message'));
            }
            throw e;
        }
    };

    const reopen = async () => {
        try {
            await reopenMutation.mutateAsync();
            NotificationHelper.showSuccess(t('Chat reopened'));
        } catch (e) {
            console.error(e);
            if (e instanceof ApiError && e.message === ErrorCodeEnum.CHAT_SHIFT_DELETED.value) {
                NotificationHelper.showError(t('error|This chat cannot be reopened because its shift was deleted'));
            } else {
                NotificationHelper.showError(t('error|Could not reopen chat'));
            }
        }
    };

    const startEditingTitle = () => {
        renameSubmittedRef.current = false;
        setTitleDraft(detail?.title ?? '');
        setIsEditingTitle(true);
    };

    const submitRename = async () => {
        if (renameMutation.isPending || renameSubmittedRef.current) return;
        const trimmed = titleDraft.trim();
        if (!trimmed || trimmed === (detail?.title ?? '')) {
            setIsEditingTitle(false);
            return;
        }
        renameSubmittedRef.current = true;
        try {
            await renameMutation.mutateAsync(trimmed);
            NotificationHelper.showSuccess(t('Group name updated'));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not rename group'));
        } finally {
            setIsEditingTitle(false);
        }
    };

    // Loading and error share the window frame so the mobile back arrow is always
    // present (no trap mid-load) and both read the same as the loaded conversation.
    const renderShell = (content: React.ReactNode) => (
        <Box className={chatClasses.window}>
            <Box className={classes.windowHeader}>
                <ActionIcon variant="subtle" color="gray" onClick={onBack} aria-label={t('Back')}>
                    <LuArrowLeft size={18} />
                </ActionIcon>
                <Text fw={700}>{t('Chat')}</Text>
            </Box>
            {content}
        </Box>
    );

    if (!conversationId) {
        return (
            <ChatEmptyState
                icon={<LuMessageCircle size={40} color="var(--mantine-color-gray-3)" />}
                message={t('Select a chat')}
            />
        );
    }

    if (detailQuery.isLoading || messagesQuery.isLoading) {
        return renderShell(
            <Center flex={1}>
                <Loader />
            </Center>,
        );
    }

    if ((detailQuery.isError || messagesQuery.isError) && (!detail || !messagesQuery.data)) {
        return renderShell(
            <ChatErrorState
                message={t("Couldn't load this conversation")}
                onRetry={() => {
                    detailQuery.refetch();
                    messagesQuery.refetch();
                }}
            />,
        );
    }

    const title = detail ? conversationTitle(detail, t) : t('Chat');
    const showMenu = detail !== null && (canManageSettings || hasLifecycleAction || canDeleteConversation);
    // Everyone in the internal admin group is an admin, so its name is narrowed to global
    // admins — the same rule the backend enforces.
    const canRenameGroup = canEdit && isAdmin && isGroupChat && !isClosed && (!isSystemConversation || isGlobalAdmin);

    return (
        <Box className={chatClasses.window}>
            {detail?.pinned_message && isGroupChat && (
                <PinnedMessageBanner
                    message={detail.pinned_message}
                    isAdmin={canPin}
                    onUnpin={handleUnpin}
                    isUnpinning={unpinMutation.isPending}
                />
            )}
            <Box className={classes.windowHeader}>
                <ActionIcon variant="subtle" color="gray" onClick={onBack} aria-label={t('Back')}>
                    <LuArrowLeft size={18} />
                </ActionIcon>
                <DAvatar size="md" radius="xl" name={title} />
                <Box style={{ minWidth: 0, flex: 1 }}>
                    <Group gap={6} wrap="nowrap">
                        {isEditingTitle ? (
                            <TextInput
                                value={titleDraft}
                                onChange={(e) => setTitleDraft(e.currentTarget.value)}
                                onBlur={submitRename}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        submitRename();
                                    } else if (e.key === 'Escape') {
                                        setIsEditingTitle(false);
                                    }
                                }}
                                size="xs"
                                autoFocus
                                style={{ flex: 1 }}
                                disabled={renameMutation.isPending}
                            />
                        ) : (
                            <>
                                <Text fw={700} truncate>
                                    {title}
                                </Text>
                                {canRenameGroup && (
                                    <ActionIcon
                                        variant="subtle"
                                        color="gray"
                                        size="xs"
                                        aria-label={t('Rename group')}
                                        onClick={() => startEditingTitle()}
                                    >
                                        <LuPencil size={12} />
                                    </ActionIcon>
                                )}
                            </>
                        )}
                        {detail &&
                            (isSystemConversation ? (
                                <Badge size="xs" variant="light" color="grape" style={{ flexShrink: 0 }}>
                                    {t('Internal')}
                                </Badge>
                            ) : (
                                <Badge
                                    size="xs"
                                    variant="light"
                                    color={conversationTypeBadgeColor(detail.type)}
                                    style={{ flexShrink: 0 }}
                                >
                                    {conversationTypeLabel(detail.type, t)}
                                </Badge>
                            ))}
                        {isClosed && (
                            <Badge size="xs" variant="light" color="orange" style={{ flexShrink: 0 }}>
                                {isShiftDeleted ? t('Closed (shift deleted)') : t('Closed')}
                            </Badge>
                        )}
                    </Group>
                    <UnstyledButton onClick={roster.open}>
                        <Text size="xs" c="dimmed">
                            {t('{{count}} participants', { count: participants.length })}
                        </Text>
                    </UnstyledButton>
                </Box>
                {showMenu && (
                    <Menu position="bottom-end" withinPortal>
                        <Menu.Target>
                            <ActionIcon variant="subtle" color="gray" aria-label={t('Chat actions')}>
                                <LuEllipsisVertical size={18} />
                            </ActionIcon>
                        </Menu.Target>
                        <Menu.Dropdown>
                            {canManageSettings && (
                                <Menu.Item leftSection={<LuSettings size={16} />} onClick={permissionsModal.open}>
                                    {t('Employee permissions')}
                                </Menu.Item>
                            )}
                            {isClosed && canReopen && (
                                <Menu.Item leftSection={<LuLockOpen size={16} />} onClick={reopen}>
                                    {t('Reopen chat')}
                                </Menu.Item>
                            )}
                            {!isClosed && canCloseReopen && (
                                <Menu.Item color="red" leftSection={<LuLock size={16} />} onClick={closeModal.open}>
                                    {t('Close chat')}
                                </Menu.Item>
                            )}
                            {canDeleteConversation && conversationId !== null && (
                                <>
                                    {(canManageSettings || hasLifecycleAction) && <Menu.Divider />}
                                    <Menu.Item
                                        color="red"
                                        leftSection={<LuTrash2 size={16} />}
                                        onClick={deleteConversationModal.open}
                                    >
                                        {t('Delete conversation')}
                                    </Menu.Item>
                                </>
                            )}
                        </Menu.Dropdown>
                    </Menu>
                )}
            </Box>
            <MessageList
                messages={messages}
                currentUserId={currentUserId}
                senderNameById={senderNameById}
                senderStaffNoById={senderStaffNoById}
                hasMore={hasMoreOlder}
                isLoadingOlder={isLoadingOlder}
                onLoadOlder={loadOlder}
                isAdmin={canPin}
                canDeleteMessage={canDeleteMessage}
                onPinMessage={canPin ? handlePinMessage : undefined}
                onDeleteMessage={canDeleteMessage ? handleDeleteMessage : undefined}
                pinnedMessageId={detail?.pinned_message?.id ?? null}
                readReceipts={readReceipts}
                onOpenReadReceipts={setReceiptsMessageId}
            />
            <ReadReceiptsModal
                conversationId={conversationId}
                messageId={receiptsMessageId}
                onClose={() => setReceiptsMessageId(null)}
            />
            {isClosed && !canWriteWhenClosed ? (
                <Group
                    gap={6}
                    justify="center"
                    align="center"
                    c="dimmed"
                    py="xs"
                    style={{ borderTop: '1px solid var(--mantine-color-gray-2)' }}
                >
                    <LuMessageSquareOff size={14} />
                    <Text size="xs">
                        {isShiftDeleted
                            ? t('This chat is closed because its shift was deleted')
                            : t('This chat is closed')}
                    </Text>
                    {canReopen && (
                        <Button
                            size="compact-xs"
                            variant="subtle"
                            leftSection={<LuLockOpen size={12} />}
                            loading={reopenMutation.isPending}
                            onClick={reopen}
                        >
                            {t('Reopen')}
                        </Button>
                    )}
                </Group>
            ) : (
                <MessageComposer onSend={sendMessage} isSending={sendMutation.isPending} />
            )}

            <ParticipantsDrawer
                opened={rosterOpened}
                onClose={roster.close}
                participants={participants}
                currentUserId={currentUserId}
                conversationId={conversationId}
                conversationType={detail?.type}
                extraParticipantUserIds={detail?.extra_participant_user_ids ?? []}
                canManage={canManageParticipants}
                canManageDeputy={canManageDeputy}
            />
            <CloseConversationModal opened={closeOpened} onClose={closeModal.close} conversationId={conversationId} />
            {detail && (
                <EmployeePermissionsModal
                    opened={permissionsOpened}
                    onClose={permissionsModal.close}
                    conversationId={conversationId}
                    permissions={detail.permissions}
                />
            )}
            <Modal
                opened={deleteConversationOpened}
                onClose={deleteConversationModal.close}
                title={t('Delete conversation')}
                centered
                size="sm"
            >
                <Text size="sm">
                    {t('This conversation and all messages will be permanently deleted and cannot be recovered.')}
                </Text>
                <Group justify="flex-end" mt="md">
                    <Button variant="default" onClick={deleteConversationModal.close}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        color="red"
                        loading={isDeletingConversation}
                        onClick={async () => {
                            if (conversationId === null) return;
                            try {
                                await onDeleteConversation(conversationId);
                                deleteConversationModal.close();
                            } catch {
                                // Error notification is handled by the mutation owner.
                            }
                        }}
                    >
                        {t('Delete')}
                    </Button>
                </Group>
            </Modal>
        </Box>
    );
};

type AdminChatPortalProps = {
    opened: boolean;
    onClose: () => void;
};

const AdminChatPortal = ({ opened, onClose }: AdminChatPortalProps) => {
    const { t } = useTranslation();
    const auth = useAtomValue(authAtom);
    const queryClient = useQueryClient();
    const [chatIntent, setChatIntent] = useAtom(chatIntentAtom);
    const [createMode, setCreateMode] = useState(false);
    const [selectedConversationId, setSelectedConversationId] = useState<number | null>(null);
    const [realtimeMessages, setRealtimeMessages] = useState<ChatMessage[]>([]);
    const [deletedMessageIds, setDeletedMessageIds] = useState<Set<number>>(() => new Set());
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState<ChatConversationStatusFilter>('open');
    const [debouncedSearch] = useDebouncedValue(search, 300);

    // Mirror the selection in a ref so the realtime handler can read the current value without
    // forcing the channel-subscription effect to tear down and re-subscribe on every selection.
    const selectedConversationIdRef = useRef(selectedConversationId);
    selectedConversationIdRef.current = selectedConversationId;

    const canView = !!auth && RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_VIEW.value]);
    const canCreate = !!auth && RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_CREATE.value]);
    const canEdit = !!auth && RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_EDIT.value]);
    const canDeleteMessagePermission =
        !!auth && RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_DELETE_MESSAGE.value]);
    const canDeleteConversationPermission =
        !!auth && RoleHelper.hasAnyPermission(auth.permissions, [PermissionNameEnum.CHAT_DELETE_CONVERSATION.value]);
    const isGlobalAdmin = !!auth && RoleHelper.hasAnyRole(auth.roles, [RoleNameEnum.GLOBAL_ADMIN.value]);
    const deleteConversationMutation = useDeleteAdminChatConversationMutation();

    // Routes an incoming intent to the right panel. An `open` intent is fully handled here
    // (it owns `selectedConversationId`) and clears itself; a `create-direct` intent only
    // flips into create mode so CreateChatPanel mounts and seeds/clears the intent itself.
    useEffect(() => {
        if (!chatIntent) {
            return;
        }
        if (chatIntent.kind === 'open') {
            setCreateMode(false);
            setSelectedConversationId(chatIntent.conversationId);
            setChatIntent(null);
        } else {
            setCreateMode(true);
            setSelectedConversationId(null);
        }
    }, [chatIntent, setChatIntent]);

    const trimmedSearch = debouncedSearch.trim();
    const conversationsQuery = useAdminChatConversationsQuery(
        opened && canView,
        trimmedSearch.length >= 2 ? trimmedSearch : undefined,
        statusFilter,
    );
    const conversations = conversationsQuery.data?.data?.data ?? [];
    const conversationIds = useMemo(() => conversations.map((conversation) => conversation.id), [conversations]);

    useEffect(() => {
        setRealtimeMessages([]);
        setDeletedMessageIds(new Set());
    }, [selectedConversationId]);

    const markMessageDeleted = useCallback((messageId: number) => {
        setDeletedMessageIds((current) => {
            if (current.has(messageId)) {
                return current;
            }
            return new Set(current).add(messageId);
        });
        setRealtimeMessages((current) => current.filter((message) => message.id !== messageId));
    }, []);

    useEffect(() => {
        if (!opened || conversationIds.length === 0) {
            return;
        }

        const echo = getChatEcho();
        if (!echo) {
            return;
        }

        const onMessage = (message: ChatMessage) => {
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, message.conversation_id] });

            if (message.conversation_id === selectedConversationIdRef.current) {
                setRealtimeMessages((current) => uniqueMessages([...current, message]));
            }
        };

        const onStateChange = (payload: { id: number }) => {
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, payload.id] });
        };

        const onMessageDeleted = (payload: { id: number; conversation_id: number }) => {
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            queryClient.invalidateQueries({
                queryKey: [ADMIN_CHAT_CONVERSATION_QUERY_KEY, payload.conversation_id],
            });
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, payload.conversation_id] });
            if (payload.conversation_id === selectedConversationIdRef.current) {
                markMessageDeleted(payload.id);
            }
        };

        const onRead = (payload: {
            conversation_id: number;
            user_id: number;
            last_read_message_id: number | null;
            last_read_at: string | null;
        }) => {
            // Patch the cached roster in place instead of refetching: the event already carries
            // everything the receipt avatars need.
            queryClient.setQueryData<ApiResponse<ChatConversationDetail>>(
                [ADMIN_CHAT_CONVERSATION_QUERY_KEY, payload.conversation_id],
                (current) => {
                    if (!current?.data) {
                        return current;
                    }
                    return {
                        ...current,
                        data: {
                            ...current.data,
                            participants: current.data.participants.map((participant) =>
                                participant.user_id === payload.user_id
                                    ? {
                                          ...participant,
                                          last_read_message_id: payload.last_read_message_id,
                                          last_read_at: payload.last_read_at,
                                      }
                                    : participant,
                            ),
                        },
                    };
                },
            );
            queryClient.invalidateQueries({
                queryKey: [ADMIN_CHAT_MESSAGE_RECEIPTS_QUERY_KEY, payload.conversation_id],
            });
        };

        const onConversationDeleted = (payload: { id: number }) => {
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
            if (selectedConversationIdRef.current === payload.id) {
                setSelectedConversationId(null);
            }
        };

        conversationIds.forEach((conversationId) => {
            echo.private(`chat.conversation.${conversationId}`)
                .listen('.chat.message.new', onMessage)
                .listen('.chat.conversation.state', onStateChange)
                .listen('.chat.message.deleted', onMessageDeleted)
                .listen('.chat.conversation.deleted', onConversationDeleted);
            echo.private(`chat.conversation.${conversationId}.read`).listen('.chat.conversation.read', onRead);
        });

        return () => {
            conversationIds.forEach((conversationId) => {
                echo.leave(`chat.conversation.${conversationId}`);
                echo.leave(`chat.conversation.${conversationId}.read`);
            });
        };
    }, [conversationIds, markMessageDeleted, opened, queryClient]);

    useEffect(() => {
        if (!opened || !auth?.user.id) {
            return;
        }

        const echo = getChatEcho();
        if (!echo) {
            return;
        }

        const userChannelName = `user-${auth.user.id}`;
        echo.private(userChannelName).listen('.chat.conversation.created', () => {
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_CONVERSATIONS_QUERY_KEY] });
        });

        return () => {
            echo.leave(userChannelName);
        };
    }, [auth?.user.id, opened, queryClient]);

    if (!canView || !auth) {
        return null;
    }

    const conversationList = (
        <ConversationList
            conversations={conversations}
            search={search}
            onSearchChange={setSearch}
            statusFilter={statusFilter}
            onStatusFilterChange={setStatusFilter}
            selectedConversationId={selectedConversationId}
            isLoading={conversationsQuery.isLoading}
            isError={conversationsQuery.isError}
            canCreate={canCreate}
            onSelect={setSelectedConversationId}
            onCreate={() => setCreateMode(true)}
            onRetry={() => conversationsQuery.refetch()}
            onClose={onClose}
        />
    );

    const handleDeleteConversation = async (conversationId: number) => {
        try {
            await deleteConversationMutation.mutateAsync(conversationId);
            setSelectedConversationId(null);
            NotificationHelper.showSuccess(t('Conversation deleted'));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not delete conversation'));
            throw e;
        }
    };

    const chatWindow = (
        <ChatWindow
            conversationId={selectedConversationId}
            currentUserId={auth.user.id}
            canEdit={canEdit}
            canDeleteMessagePermission={canDeleteMessagePermission}
            canDeleteConversationPermission={canDeleteConversationPermission}
            isGlobalAdmin={isGlobalAdmin}
            isPortalOpen={opened}
            realtimeMessages={realtimeMessages}
            deletedMessageIds={deletedMessageIds}
            isDeletingConversation={deleteConversationMutation.isPending}
            onLocalMessage={(message) => {
                setRealtimeMessages((current) => uniqueMessages([...current, message]));
            }}
            onMessageDeleted={markMessageDeleted}
            onBack={() => setSelectedConversationId(null)}
            onDeleteConversation={handleDeleteConversation}
        />
    );

    const createPanel = (
        <CreateChatPanel
            onCancel={() => setCreateMode(false)}
            onCreated={(conversationId) => {
                setSelectedConversationId(conversationId);
                setCreateMode(false);
            }}
        />
    );

    // Single-column "Messenger" flow: show the conversation list, then slide to the
    // selected chat (with a back arrow) instead of keeping both columns side by side.
    let body: React.ReactNode;
    if (createMode) {
        body = createPanel;
    } else if (selectedConversationId !== null) {
        body = chatWindow;
    } else {
        body = conversationList;
    }

    return (
        <Drawer
            opened={opened}
            onClose={onClose}
            withCloseButton={false}
            position="right"
            size="min(420px, 100vw)"
            styles={{
                content: { display: 'flex', flexDirection: 'column' },
                body: { flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' },
            }}
        >
            <Box style={{ flex: 1, minHeight: 0 }}>{body}</Box>
        </Drawer>
    );
};

export default AdminChatPortal;
