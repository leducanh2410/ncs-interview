import React, { useEffect, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { LuMessageSquareOff, LuSettings, LuUserPlus, LuUsers, LuX } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Box,
    Breadcrumbs,
    Button,
    Center,
    Flex,
    Group,
    Loader,
    Paper,
    Stack,
    Text,
    Title,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useQueryClient } from '@tanstack/react-query';
import { useAtomValue } from 'jotai';
import { v4 as uuidv4 } from 'uuid';
import { ChatParticipant } from '@/types/models/chat';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import { ApiError } from '@/helpers/api.helper';
import { getChatEcho } from '@/helpers/chat_echo.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import DAvatar from '@/components/DAvatar';
import DUserSelectModal from '@/components/DUserSelectModal';
import SupportTypeLabel from '@/components/SupportTypeLabel';
import EmployeePermissionsModal from '@/components/chat/EmployeePermissionsModal';
import MessageComposer from '@/components/chat/MessageComposer';
import MessageList from '@/components/chat/MessageList';
import {
    ADMIN_CHAT_MESSAGES_QUERY_KEY,
    useAdminChatConversationQuery,
    useAdminChatMessagesQuery,
    useDeleteAdminChatMessageMutation,
    useMarkAdminChatReadMutation,
    useSendAdminChatMessageMutation,
} from '@/components/chat/api';
import chatClasses from '@/components/chat/chat.module.css';
import { authAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useAssignSupportAdminsMutation, useRemoveSupportAdminsMutation } from '@/pages/admin/chat/support/api';

const SUPPORT_ADMIN_PERMISSIONS = [PermissionNameEnum.CHAT_SUPPORT.value, PermissionNameEnum.CHAT_VIEW.value];

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const auth = useAtomValue(authAtom);
    const queryClient = useQueryClient();
    const perms = usePermissions(['CHAT_ASSIGN', 'CHAT_SUPPORT', 'CHAT_VIEW', 'CHAT_DELETE_MESSAGE', 'USER_LIST']);

    const conversationId = router.query.id ? Number(router.query.id) : null;
    const currentUserId = auth?.user.id ?? 0;

    const [addModalOpened, addModal] = useDisclosure(false);
    const [permissionsModalOpened, permissionsModal] = useDisclosure(false);
    const lastMarkedReadId = useRef<number | null>(null);

    const detailQuery = useAdminChatConversationQuery(conversationId, perms.CHAT_ASSIGN);
    const messagesQuery = useAdminChatMessagesQuery(conversationId, perms.CHAT_ASSIGN);
    const assignMutation = useAssignSupportAdminsMutation(conversationId);
    const removeMutation = useRemoveSupportAdminsMutation(conversationId);
    const sendMutation = useSendAdminChatMessageMutation(conversationId);
    const markReadMutation = useMarkAdminChatReadMutation(conversationId);
    const deleteMessageMutation = useDeleteAdminChatMessageMutation(conversationId);
    const detail = detailQuery.data?.data ?? null;
    const participants = useMemo(() => detail?.participants ?? [], [detail]);

    const adminParticipants = useMemo(
        () => participants.filter((participant: ChatParticipant) => participant.role === 'admin'),
        [participants],
    );
    const employeeParticipant = useMemo(
        () => participants.find((participant: ChatParticipant) => participant.role === 'employee') ?? null,
        [participants],
    );
    const isAssignedToMe = useMemo(
        () => adminParticipants.some((participant: ChatParticipant) => participant.user_id === currentUserId),
        [adminParticipants, currentUserId],
    );

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

    const messages = messagesQuery.data?.data?.messages ?? [];

    const isClosed = detail?.status === 'closed';
    const canSend = isAssignedToMe && !isClosed;
    const canDeleteMessages = perms.CHAT_DELETE_MESSAGE && isAssignedToMe;

    const title = useMemo(() => {
        if (detail?.title) return detail.title;
        if (employeeParticipant?.name) return t('Support from {{name}}', { name: employeeParticipant.name });
        return t('Support request');
    }, [detail?.title, employeeParticipant?.name, t]);

    // Live updates for the open support conversation.
    useEffect(() => {
        if (!conversationId || !perms.CHAT_ASSIGN) {
            return;
        }

        const echo = getChatEcho();
        if (!echo) {
            return;
        }

        const channelName = `chat.conversation.${conversationId}`;
        echo.private(channelName)
            .listen('.chat.message.new', () => {
                queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
            })
            .listen('.chat.message.deleted', () => {
                queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
            })
            .listen('.chat.conversation.deleted', () => {
                router.replace(RouteConfig.ADMIN_CHAT_SUPPORT_LIST.path);
            });

        return () => {
            echo.leave(channelName);
        };
    }, [conversationId, perms.CHAT_ASSIGN, queryClient, router]);

    // Mark read only when the admin is an actual participant (assigned).
    useEffect(() => {
        if (!conversationId || !isAssignedToMe) {
            return;
        }
        const newestMessageId = messages[messages.length - 1]?.id;
        if (!newestMessageId || lastMarkedReadId.current === newestMessageId) {
            return;
        }
        lastMarkedReadId.current = newestMessageId;
        markReadMutation.mutate(newestMessageId);
    }, [conversationId, isAssignedToMe, markReadMutation, messages]);

    const assignAdmins = async (userIds: number[]) => {
        if (userIds.length === 0) return;
        try {
            await assignMutation.mutateAsync({ admin_user_ids: userIds });
            NotificationHelper.showSuccess(t('Admins assigned'));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not assign admins'));
        }
    };

    const assignToMe = async () => {
        try {
            await assignMutation.mutateAsync({ admin_user_ids: [currentUserId] });
            NotificationHelper.showSuccess(t('You are now assigned to this support chat'));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not assign admins'));
        }
    };

    const removeAdmin = async (userId: number) => {
        try {
            await removeMutation.mutateAsync({ admin_user_ids: [userId] });
            NotificationHelper.showSuccess(t('Admin removed'));
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not remove admin'));
        }
    };

    const sendMessage = async (trimmed: string, files: File[]) => {
        if (!conversationId) return;

        try {
            const response = await sendMutation.mutateAsync({
                body: trimmed || undefined,
                client_message_id: uuidv4(),
                files,
            });
            queryClient.invalidateQueries({ queryKey: [ADMIN_CHAT_MESSAGES_QUERY_KEY, conversationId] });
            const maskedKeywords = response.data?.masked_keywords ?? [];
            if (maskedKeywords.length > 0) {
                NotificationHelper.showInfo(
                    t('The following banned keywords were hidden: {{keywords}}', {
                        keywords: maskedKeywords.join(', '),
                    }),
                );
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

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_CHAT_SUPPORT_LIST.path}>
                    {t('Support chats')}
                </Anchor>
                <Text>{title}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center" my="md">
                <Group gap="sm">
                    <Title order={4} lh="48px">
                        {title}
                    </Title>
                    <Badge color={isClosed ? 'gray' : 'teal'} variant="light">
                        {isClosed ? t('Closed') : t('Open')}
                    </Badge>
                    <SupportTypeLabel type={detail?.support_type} />
                </Group>
            </Flex>

            {detailQuery.isLoading ? (
                <Center h={320}>
                    <Loader />
                </Center>
            ) : !detail ? (
                <Paper p="xl">
                    <Center>
                        <Text c="dimmed">{t('Support chat not found')}</Text>
                    </Center>
                </Paper>
            ) : (
                <Flex gap="md" align="stretch" direction={{ base: 'column', md: 'row' }}>
                    <Box w={{ base: '100%', md: 320 }} style={{ flexShrink: 0 }}>
                        <Paper p="md" withBorder>
                            <Text size="sm" fw={600} mb="xs">
                                {t('Requester')}
                            </Text>
                            {detail.type === 'guest_support' ? (
                                <Stack gap={4} mb="md">
                                    <Group gap={6}>
                                        <Text fw={500}>{detail.guest_info?.name ?? '—'}</Text>
                                        <Badge color="teal" variant="filled" size="xs">
                                            {t('Guest')}
                                        </Badge>
                                    </Group>
                                    <Text size="sm" c="dimmed">
                                        {detail.guest_info?.email}
                                    </Text>
                                </Stack>
                            ) : (
                                <Group gap="xs" wrap="nowrap" mb="md">
                                    {employeeParticipant && perms.USER_LIST ? (
                                        <Link
                                            href={RouteHelper.getPath('ADMIN_USER_DETAIL', {
                                                id: employeeParticipant.user_id,
                                            })}
                                            style={{ lineHeight: 0, flexShrink: 0 }}
                                        >
                                            <DAvatar
                                                size="md"
                                                radius="xl"
                                                name={employeeParticipant.name || t('Unknown user')}
                                                src={employeeParticipant.profile_picture ?? undefined}
                                            />
                                        </Link>
                                    ) : (
                                        <DAvatar
                                            size="md"
                                            radius="xl"
                                            name={employeeParticipant?.name || t('Unknown user')}
                                            src={employeeParticipant?.profile_picture ?? undefined}
                                        />
                                    )}
                                    <Box style={{ minWidth: 0 }}>
                                        <Text size="sm" truncate>
                                            {employeeParticipant?.name || t('Unknown user')}
                                            {employeeParticipant?.staff_no && (
                                                <Text span size="sm" c="dimmed">
                                                    {' '}
                                                    ({employeeParticipant.staff_no})
                                                </Text>
                                            )}
                                        </Text>
                                    </Box>
                                </Group>
                            )}

                            <Group justify="space-between" mb="xs">
                                <Text size="sm" fw={600}>
                                    {t('Assigned admins')}
                                </Text>
                                <Badge variant="light">{adminParticipants.length}</Badge>
                            </Group>
                            <Stack gap={6} mb="sm">
                                {adminParticipants.length === 0 ? (
                                    <Text size="sm" c="dimmed">
                                        {t('Unassigned')}
                                    </Text>
                                ) : (
                                    adminParticipants.map((participant: ChatParticipant) => (
                                        <Group key={participant.user_id} gap="xs" wrap="nowrap">
                                            {perms.USER_LIST ? (
                                                <Link
                                                    href={RouteHelper.getPath('ADMIN_USER_DETAIL', {
                                                        id: participant.user_id,
                                                    })}
                                                    style={{ lineHeight: 0, flexShrink: 0 }}
                                                >
                                                    <DAvatar
                                                        size="sm"
                                                        radius="xl"
                                                        name={participant.name || t('Unknown user')}
                                                        src={participant.profile_picture ?? undefined}
                                                    />
                                                </Link>
                                            ) : (
                                                <DAvatar
                                                    size="sm"
                                                    radius="xl"
                                                    name={participant.name || t('Unknown user')}
                                                    src={participant.profile_picture ?? undefined}
                                                />
                                            )}
                                            <Box style={{ flex: 1, minWidth: 0 }}>
                                                <Text size="sm" truncate>
                                                    {participant.name || t('Unknown user')}
                                                    {participant.user_id === currentUserId ? ` (${t('You')})` : ''}
                                                    {participant.staff_no && (
                                                        <Text span size="sm" c="dimmed">
                                                            {' '}
                                                            ({participant.staff_no})
                                                        </Text>
                                                    )}
                                                </Text>
                                            </Box>
                                            <ActionIcon
                                                variant="subtle"
                                                color="red"
                                                size="sm"
                                                loading={removeMutation.isPending}
                                                onClick={() => removeAdmin(participant.user_id)}
                                                aria-label={t('Remove')}
                                            >
                                                <LuX size={14} />
                                            </ActionIcon>
                                        </Group>
                                    ))
                                )}
                            </Stack>

                            <Stack gap="xs">
                                {!isAssignedToMe && perms.CHAT_ASSIGN && perms.CHAT_SUPPORT && perms.CHAT_VIEW && (
                                    <Button
                                        leftSection={<LuUserPlus size={16} />}
                                        loading={assignMutation.isPending}
                                        onClick={assignToMe}
                                    >
                                        {t('Assign to me')}
                                    </Button>
                                )}
                                <Button variant="default" leftSection={<LuUsers size={16} />} onClick={addModal.open}>
                                    {t('Add admins')}
                                </Button>
                                {!isClosed && (perms.CHAT_ASSIGN || isAssignedToMe) && (
                                    <Button
                                        variant="default"
                                        leftSection={<LuSettings size={16} />}
                                        onClick={permissionsModal.open}
                                    >
                                        {t('Employee permissions')}
                                    </Button>
                                )}
                            </Stack>
                        </Paper>
                    </Box>

                    <Box flex={1} mih={0}>
                        <Paper withBorder p="md" h="calc(100vh - 240px)" mih={420}>
                            <Box className={chatClasses.window}>
                                {messagesQuery.isLoading ? (
                                    <Center h="100%">
                                        <Loader />
                                    </Center>
                                ) : (
                                    <MessageList
                                        messages={messages}
                                        currentUserId={currentUserId}
                                        senderNameById={senderNameById}
                                        senderStaffNoById={senderStaffNoById}
                                        canDeleteMessage={canDeleteMessages}
                                        onDeleteMessage={
                                            canDeleteMessages
                                                ? async (messageId) => {
                                                      try {
                                                          await deleteMessageMutation.mutateAsync(messageId);
                                                      } catch (e) {
                                                          console.error(e);
                                                          NotificationHelper.showError(
                                                              t('error|Could not delete message'),
                                                          );
                                                          throw e;
                                                      }
                                                  }
                                                : undefined
                                        }
                                    />
                                )}
                                {canSend ? (
                                    <MessageComposer onSend={sendMessage} isSending={sendMutation.isPending} />
                                ) : (
                                    <Group
                                        gap={6}
                                        justify="center"
                                        c="dimmed"
                                        py="xs"
                                        style={{ borderTop: '1px solid var(--mantine-color-gray-2)' }}
                                    >
                                        <LuMessageSquareOff size={14} />
                                        <Text size="xs">
                                            {isClosed
                                                ? t('This support chat is closed')
                                                : t('Assign yourself to reply in this chat')}
                                        </Text>
                                    </Group>
                                )}
                            </Box>
                        </Paper>
                    </Box>
                </Flex>
            )}

            <DUserSelectModal
                isOpen={addModalOpened}
                onClose={addModal.close}
                onConfirm={(users: User[]) => assignAdmins(users.map((user) => user.id))}
                title={t('Add support admins')}
                confirmButtonLabel={t('Assign')}
                searchPlaceholder={t('Search admin')}
                extraParams={{ permission: SUPPORT_ADMIN_PERMISSIONS }}
            />
            {detail && (
                <EmployeePermissionsModal
                    opened={permissionsModalOpened}
                    onClose={permissionsModal.close}
                    conversationId={conversationId}
                    permissions={detail.permissions}
                />
            )}
        </>
    );
};

export default IndexPage;
