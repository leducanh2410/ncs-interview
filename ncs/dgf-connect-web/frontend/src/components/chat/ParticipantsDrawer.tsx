import { type ReactNode, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuRefreshCw, LuSearch, LuShield, LuShieldOff, LuUserPlus, LuX } from 'react-icons/lu';
import { ActionIcon, Badge, Button, Center, Drawer, Group, Loader, Stack, Text, TextInput } from '@mantine/core';
import { useDebouncedValue, useDisclosure } from '@mantine/hooks';
import { ChatConversationType, ChatParticipant } from '@/types/models/chat';
import { User } from '@/types/models/user';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DAvatar from '@/components/DAvatar';
import DUserSelectModal from '@/components/DUserSelectModal';
import {
    useAddChatParticipantsMutation,
    useAdminChatParticipantsQuery,
    useAssignChatParticipantRoleMutation,
    useRemoveChatParticipantMutation,
} from '@/components/chat/api';

type ParticipantsDrawerProps = {
    opened: boolean;
    onClose: () => void;
    participants: ChatParticipant[];
    currentUserId: number;
    conversationId?: number | null;
    conversationType?: ChatConversationType | null;
    extraParticipantUserIds?: number[];
    canManage?: boolean;
    canManageDeputy?: boolean;
};

const isScopedType = (type: ChatConversationType | null | undefined): boolean =>
    type === 'shift_group' || type === 'user_group';

const participantBadges = (participant: ChatParticipant, t: (key: string) => string) => {
    const badges: ReactNode[] = [];

    if (participant.role === 'admin') {
        badges.push(
            <Badge key="leader" size="sm" variant="light" color="blue">
                {t('Group leader')}
            </Badge>,
        );
    } else if (participant.role === 'deputy') {
        badges.push(
            <Badge key="deputy" size="sm" variant="light" color="teal">
                {t('Deputy')}
            </Badge>,
        );
    }

    if (participant.is_global_admin) {
        badges.push(
            <Badge key="global-admin" size="sm" variant="light" color="grape">
                {t('Global admin')}
            </Badge>,
        );
    }

    if (badges.length === 0) {
        return null;
    }

    return <Group gap={4}>{badges}</Group>;
};

const ParticipantsDrawer = ({
    opened,
    onClose,
    participants,
    currentUserId,
    conversationId = null,
    conversationType = null,
    extraParticipantUserIds = [],
    canManage = false,
    canManageDeputy = false,
}: ParticipantsDrawerProps) => {
    const { t } = useTranslation();
    const [addModalOpened, addModal] = useDisclosure(false);
    const [removingId, setRemovingId] = useState<number | null>(null);
    const [assigningId, setAssigningId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const [debouncedSearch] = useDebouncedValue(search, 300);
    const [page, setPage] = useState(1);
    const [trueTotal, setTrueTotal] = useState(participants.length);

    const addMutation = useAddChatParticipantsMutation(conversationId);
    const removeMutation = useRemoveChatParticipantMutation(conversationId);
    const assignRoleMutation = useAssignChatParticipantRoleMutation(conversationId);

    const participantsQuery = useAdminChatParticipantsQuery(conversationId, debouncedSearch, page, opened);

    const lastPage = participantsQuery.data?.data?.last_page ?? 1;
    const hasMore = page < lastPage;

    const [allParticipants, setAllParticipants] = useState<ChatParticipant[]>([]);
    const currentPageRef = useRef(page);
    currentPageRef.current = page;

    useEffect(() => {
        setSearch('');
        setPage(1);
        setAllParticipants([]);
        setTrueTotal(participants.length);
    }, [conversationId, participants.length]);

    useEffect(() => {
        if (!participantsQuery.isSuccess) return;

        const newItems = participantsQuery.data.data?.data ?? [];
        if (currentPageRef.current === 1) {
            setAllParticipants(newItems);
        } else {
            setAllParticipants((prev) => {
                const newIds = new Set(newItems.map((participant) => participant.user_id));
                return [...prev.filter((participant) => !newIds.has(participant.user_id)), ...newItems];
            });
        }

        if (!debouncedSearch) {
            setTrueTotal(participantsQuery.data.data?.total ?? participants.length);
        }
    }, [participantsQuery.data, participantsQuery.isSuccess, debouncedSearch, participants.length]);

    const scoped = isScopedType(conversationType);

    const refreshFirstPage = () => {
        setAllParticipants([]);
        setPage(1);
    };

    const addParticipants = async (users: User[]) => {
        if (users.length === 0) return;

        try {
            await addMutation.mutateAsync({ user_ids: users.map((user) => user.id) });
            NotificationHelper.showSuccess(t('Members added'));
            refreshFirstPage();
        } catch (e) {
            console.error(e);
            if (e instanceof ApiError && e.message === 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE') {
                const invalidCount = Array.isArray(e.data?.invalid_user_ids)
                    ? (e.data.invalid_user_ids as number[]).length
                    : 0;
                NotificationHelper.showError(
                    t('error|{{count}} selected users already belong to this group', { count: invalidCount }),
                );
            } else {
                NotificationHelper.showError(t('error|Could not add members'));
            }
        }
    };

    const removeParticipant = async (userId: number) => {
        setRemovingId(userId);
        try {
            await removeMutation.mutateAsync(userId);
            NotificationHelper.showSuccess(t('Member removed'));
            refreshFirstPage();
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not remove member'));
        } finally {
            setRemovingId(null);
        }
    };

    const toggleDeputy = async (participant: ChatParticipant) => {
        const newRole = participant.role === 'deputy' ? 'employee' : 'deputy';
        setAssigningId(participant.user_id);
        try {
            await assignRoleMutation.mutateAsync({ userId: participant.user_id, role: newRole });
            NotificationHelper.showSuccess(newRole === 'deputy' ? t('Deputy assigned') : t('Deputy revoked'));
            refreshFirstPage();
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not update role'));
        } finally {
            setAssigningId(null);
        }
    };

    const fetchedParticipants = participantsQuery.data?.data?.data ?? [];
    const displayList = participantsQuery.isSuccess
        ? allParticipants.length > 0
            ? allParticipants
            : fetchedParticipants
        : participants;

    return (
        <Drawer
            opened={opened}
            onClose={onClose}
            position="right"
            size="sm"
            title={
                <Group gap="xs">
                    <Text fw={700}>{t('Participants')}</Text>
                    <Badge variant="light">{trueTotal}</Badge>
                </Group>
            }
        >
            {canManage && (
                <Button
                    fullWidth
                    variant="light"
                    mb="sm"
                    leftSection={<LuUserPlus size={16} />}
                    onClick={addModal.open}
                >
                    {scoped ? t('Add extra participant') : t('Add people')}
                </Button>
            )}

            <TextInput
                mb="sm"
                size="xs"
                value={search}
                onChange={(event) => {
                    setSearch(event.currentTarget.value);
                    setPage(1);
                }}
                placeholder={t('Search by name or employee code')}
                aria-label={t('Search by name or employee code')}
                leftSection={<LuSearch size={14} />}
                rightSection={
                    search ? (
                        <ActionIcon
                            variant="subtle"
                            color="gray"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                setPage(1);
                            }}
                            aria-label={t('Clear search')}
                        >
                            <LuX size={14} />
                        </ActionIcon>
                    ) : null
                }
            />

            {participantsQuery.isLoading && page === 1 ? (
                <Center py="xl">
                    <Loader size="sm" />
                </Center>
            ) : participantsQuery.isError ? (
                <Center py="xl">
                    <Stack align="center" gap="xs">
                        <Text size="sm" c="dimmed">
                            {t('error|Could not load participants')}
                        </Text>
                        <Button
                            variant="subtle"
                            size="xs"
                            leftSection={<LuRefreshCw size={14} />}
                            onClick={() => participantsQuery.refetch()}
                        >
                            {t('Retry')}
                        </Button>
                    </Stack>
                </Center>
            ) : displayList.length === 0 ? (
                <Center py="xl">
                    <Text size="sm" c="dimmed">
                        {t('No participants found')}
                    </Text>
                </Center>
            ) : (
                <Stack gap="xs">
                    {displayList.map((participant) => {
                        const isExtra = extraParticipantUserIds.includes(participant.user_id);
                        const canRemove = canManage && participant.role === 'employee' && (scoped ? isExtra : true);
                        const canToggleDeputy = canManageDeputy && participant.role !== 'admin';

                        return (
                            <Group key={participant.user_id} gap="xs" wrap="nowrap">
                                <DAvatar
                                    size="md"
                                    radius="xl"
                                    name={participant.name || t('Unknown user')}
                                    src={participant.profile_picture ?? undefined}
                                />
                                <Stack gap={2} style={{ flex: 1, minWidth: 0 }}>
                                    <Text size="sm" truncate>
                                        {participant.name || t('Unknown user')}
                                        {participant.user_id === currentUserId ? ` (${t('You')})` : ''}
                                    </Text>
                                    {participant.staff_no && (
                                        <Text size="xs" c="dimmed" truncate>
                                            {participant.staff_no}
                                        </Text>
                                    )}
                                    {participantBadges(participant, t)}
                                </Stack>
                                {canToggleDeputy && (
                                    <ActionIcon
                                        variant="subtle"
                                        color={participant.role === 'deputy' ? 'teal' : 'gray'}
                                        size="sm"
                                        loading={assigningId === participant.user_id}
                                        onClick={() => toggleDeputy(participant)}
                                        aria-label={
                                            participant.role === 'deputy' ? t('Revoke deputy') : t('Assign deputy')
                                        }
                                        title={participant.role === 'deputy' ? t('Revoke deputy') : t('Assign deputy')}
                                    >
                                        {participant.role === 'deputy' ? (
                                            <LuShieldOff size={14} />
                                        ) : (
                                            <LuShield size={14} />
                                        )}
                                    </ActionIcon>
                                )}
                                {canRemove && (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        size="sm"
                                        loading={removingId === participant.user_id}
                                        onClick={() => removeParticipant(participant.user_id)}
                                        aria-label={t('Remove from chat')}
                                    >
                                        <LuX size={14} />
                                    </ActionIcon>
                                )}
                            </Group>
                        );
                    })}
                </Stack>
            )}

            {hasMore && (
                <Button
                    fullWidth
                    variant="subtle"
                    mt="sm"
                    loading={participantsQuery.isFetching}
                    onClick={() => setPage((current) => current + 1)}
                >
                    {t('Load more')}
                </Button>
            )}

            {canManage && (
                <DUserSelectModal
                    isOpen={addModalOpened}
                    onClose={addModal.close}
                    onConfirm={addParticipants}
                    title={scoped ? t('Add extra participant') : t('Add people')}
                    confirmButtonLabel={t('Add')}
                />
            )}
        </Drawer>
    );
};

export default ParticipantsDrawer;
