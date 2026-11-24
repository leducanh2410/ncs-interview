import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuHistory, LuPlus, LuSave, LuSearch, LuTriangleAlert, LuX } from 'react-icons/lu';
import {
    ActionIcon,
    Badge,
    Box,
    Button,
    Center,
    ComboboxItem,
    Divider,
    Flex,
    Loader,
    Group as MantineGroup,
    Modal,
    MultiSelect,
    ScrollArea,
    Stack,
    Switch,
    Text,
    TextInput,
    Tooltip,
} from '@mantine/core';
import { Group } from '@/types/group';
import { User, UserWithAbsenceCount } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import { UserPaperStatusEnum, getPaperIncompleteWarning } from '@/enums/user_paper_status.enum';
import ApiHelper from '@/helpers/api.helper';
import AbsenceWarning from '@/pages/manage/shift/partials/absence_warning';
import {
    WeeklyWorkLoadProjection,
    formatHours,
    isAssignmentBlocked,
} from '@/pages/manage/shift/partials/weekly_work_load';
import { ShiftWeeklyWorkLoad, fetchShiftWeeklyWorkLoads } from '@/pages/manage/shift/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    onAddUser: (users: UserWithAbsenceCount[], isFixed: boolean) => void;
    shiftId: string;
    shiftHours?: number;
    workplaceId: string;
    workplaceShiftId: string;
};

type PaperWarning = { color: 'red' | 'yellow'; message: string };

const getPaperWarnings = (user: User, t: (key: string) => string): PaperWarning[] => {
    const warnings: PaperWarning[] = [];

    if (user.paper_status === UserPaperStatusEnum.REJECTED.value) {
        warnings.push({ color: 'red', message: t('Paper has been rejected') });
    }

    const expiringCards: string[] = [];
    if (user.residence_card_expiry_alert) expiringCards.push(t('Residence card'));
    if (user.student_card_expiry_alert) expiringCards.push(t('Student card'));
    if (user.passport_expiry_alert) expiringCards.push(t('Passport'));

    if (expiringCards.length > 0) {
        warnings.push({
            color: 'yellow',
            message: `${expiringCards.join(', ')}: ${t('expired or expiring soon')}`,
        });
    }

    return warnings;
};

type UserRowProps = {
    user: UserWithAbsenceCount;
    workLoad?: ShiftWeeklyWorkLoad;
    actionType: 'add' | 'remove';
    onAction: (user: UserWithAbsenceCount) => void;
};

const isAddBlocked = (user: User, workLoad: ShiftWeeklyWorkLoad | undefined, t: (key: string) => string) =>
    getPaperWarnings(user, t).some((w) => w.color === 'red') || isAssignmentBlocked(workLoad);

const UserRow = ({ user, workLoad, actionType, onAction }: UserRowProps) => {
    const { t } = useTranslation();
    const warnings = getPaperWarnings(user, t);
    const paperIncompleteWarning = getPaperIncompleteWarning(user.paper_status, t);
    const isAddDisabled = actionType === 'add' && isAddBlocked(user, workLoad, t);

    return (
        <Flex align="center" gap={8} py={6} px={8}>
            <Text size="sm" w={90} style={{ flexShrink: 0 }} c={user.staff_no ? undefined : 'dimmed'}>
                {user.staff_no || '-'}
            </Text>
            <Box style={{ flex: 1, minWidth: 0 }}>
                <Text size="sm" truncate>
                    {user.name}
                </Text>
                <Text size="xs" c="dimmed" truncate>
                    {user.email}
                </Text>
            </Box>
            {typeof user.similar_shift_count === 'number' && (
                <Tooltip
                    label={t('Worked {{count}} similar shifts in the last 90 days', {
                        count: user.similar_shift_count,
                    })}
                    withArrow
                >
                    <Badge
                        size="sm"
                        variant="light"
                        color={user.similar_shift_count > 0 ? 'blue' : 'gray'}
                        leftSection={<LuHistory size={12} />}
                        style={{ flexShrink: 0 }}
                    >
                        {user.similar_shift_count}
                    </Badge>
                </Tooltip>
            )}
            <WeeklyWorkLoadProjection workLoad={workLoad} />
            <AbsenceWarning absenceCount={user.absence_count} />
            {paperIncompleteWarning && (
                <Badge size="sm" variant="light" color="yellow" style={{ flexShrink: 0 }}>
                    {paperIncompleteWarning}
                </Badge>
            )}
            {warnings.map((warning) => (
                <Tooltip key={warning.color} label={warning.message} withArrow>
                    <Box c={warning.color} style={{ display: 'flex' }}>
                        <LuTriangleAlert size={16} />
                    </Box>
                </Tooltip>
            ))}
            {actionType === 'add' ? (
                <Tooltip
                    label={t('Cannot assign: the weekly work-hour limit would be exceeded')}
                    disabled={!isAssignmentBlocked(workLoad)}
                    withArrow
                >
                    <ActionIcon
                        size="sm"
                        variant="light"
                        color="teal"
                        onClick={() => onAction(user)}
                        disabled={isAddDisabled}
                        aria-label={t('Add')}
                    >
                        <LuPlus size={14} />
                    </ActionIcon>
                </Tooltip>
            ) : (
                <ActionIcon
                    size="sm"
                    variant="light"
                    color="red"
                    onClick={() => onAction(user)}
                    aria-label={t('Remove')}
                >
                    <LuX size={14} />
                </ActionIcon>
            )}
        </Flex>
    );
};

const renderGroupItem = (group: Group): ComboboxItem => ({
    value: group.id.toString(),
    label: getGroupDisplayName(group),
});

const AddUserModal = ({ isOpen, onClose, onAddUser, shiftId, shiftHours, workplaceId, workplaceShiftId }: Props) => {
    const { t } = useTranslation();
    const [searchInput, setSearchInput] = useState('');
    const [selectedGroupIds, setSelectedGroupIds] = useState<string[]>([]);
    const [searchResults, setSearchResults] = useState<UserWithAbsenceCount[] | null>(null);
    const [workLoads, setWorkLoads] = useState<Record<string, ShiftWeeklyWorkLoad>>({});
    const [hasWorkLoadError, setHasWorkLoadError] = useState(false);
    const [isSearching, setIsSearching] = useState(false);
    const [selectedUsers, setSelectedUsers] = useState<UserWithAbsenceCount[]>([]);
    const [isFixed, setIsFixed] = useState(false);
    const [groups, setGroups] = useState<Group[]>([]);
    const [isLoadingGroups, setIsLoadingGroups] = useState(false);
    const abortRef = useRef<AbortController | null>(null);

    const canSearch = searchInput.trim().length >= 3 || selectedGroupIds.length > 0;

    const groupOptions: ComboboxItem[] = useMemo(
        () => groups.map((group) => ({ value: group.id.toString(), label: getGroupDisplayName(group) })),
        [groups],
    );

    useEffect(() => {
        if (!isOpen) {
            return;
        }
        const controller = new AbortController();
        const fetchGroups = async () => {
            setIsLoadingGroups(true);
            try {
                const response = await ApiHelper.get(ApiConfig.ADMIN_GROUP_LIST, {
                    abortSignal: controller.signal,
                    params: { per_page: '9999' },
                });
                setGroups((response.data as { data: Group[] }).data);
            } catch (e) {
                if (!controller.signal.aborted) {
                    console.error(e);
                }
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoadingGroups(false);
                }
            }
        };
        fetchGroups();
        return () => {
            controller.abort();
        };
    }, [isOpen]);

    const reset = () => {
        setSearchInput('');
        setSelectedGroupIds([]);
        setSearchResults(null);
        setWorkLoads({});
        setHasWorkLoadError(false);
        setIsSearching(false);
        setSelectedUsers([]);
        setIsFixed(false);
        setGroups([]);
        abortRef.current?.abort();
        abortRef.current = null;
    };

    const closeHandler = () => {
        reset();
        onClose();
    };

    useEffect(() => {
        if (!isOpen) {
            reset();
        }
    }, [isOpen]);

    const selectedIds = useMemo(() => new Set(selectedUsers.map((u) => u.id)), [selectedUsers]);

    const visibleResults = useMemo(
        () => (searchResults || []).filter((u) => !selectedIds.has(u.id)),
        [searchResults, selectedIds],
    );

    const handleAdd = (user: UserWithAbsenceCount) => {
        setSelectedUsers((prev) => (prev.some((u) => u.id === user.id) ? prev : [...prev, user]));
    };

    const handleRemove = (user: UserWithAbsenceCount) => {
        setSelectedUsers((prev) => prev.filter((u) => u.id !== user.id));
    };

    const fetchWorkLoads = async (
        users: UserWithAbsenceCount[],
        abortSignal: AbortSignal,
    ): Promise<Record<string, ShiftWeeklyWorkLoad> | null> => {
        if (!shiftId || users.length === 0) {
            return {};
        }
        try {
            return await fetchShiftWeeklyWorkLoads(
                shiftId,
                users.map((user) => user.id.toString()),
                abortSignal,
            );
        } catch (e) {
            if (!abortSignal.aborted) {
                console.error(e);
            }
            return null;
        }
    };

    const runSearch = async (exactStaffNoOnly = false, autoAddFirst = false) => {
        const query = searchInput.trim();
        if (!canSearch) return;

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        try {
            setIsSearching(true);
            const params: Record<string, string | string[]> = { workplace_id: workplaceId };
            if (workplaceShiftId) {
                params.workplace_shift_id = workplaceShiftId;
            }
            if (query) {
                params.q = query;
            }
            if (exactStaffNoOnly) {
                params.staff_no = query;
            }
            if (selectedGroupIds.length > 0) {
                params.group_ids = selectedGroupIds;
            }
            const response = await ApiHelper.get(ApiConfig.MANAGE_SHIFT_USER_SEARCH, {
                abortSignal: controller.signal,
                params,
            });
            const users = response.data as UserWithAbsenceCount[];
            const foundWorkLoads = await fetchWorkLoads(users, controller.signal);
            setHasWorkLoadError(foundWorkLoads === null);
            setWorkLoads((prev) => ({ ...prev, ...foundWorkLoads }));
            setSearchResults(users);
            if (autoAddFirst && users.length === 1) {
                const onlyUser = users[0];
                if (!isAddBlocked(onlyUser, foundWorkLoads?.[onlyUser.id.toString()], t)) {
                    handleAdd(onlyUser);
                }
            }
        } catch (e) {
            if (!controller.signal.aborted) {
                console.error(e);
                setSearchResults([]);
            }
        } finally {
            if (!controller.signal.aborted) {
                setIsSearching(false);
            }
        }
    };

    return (
        <Modal opened={isOpen} onClose={closeHandler} title={t('Add user')} size="xl">
            <Flex gap="md" direction={{ base: 'column', md: 'row' }} align="stretch">
                <Stack gap="xs" w={{ base: '100%', md: '50%' }}>
                    <Stack gap="sm">
                        <TextInput
                            placeholder={t('Search user by staff no or email')}
                            leftSection={<LuSearch size={16} />}
                            value={searchInput}
                            onChange={(e) => setSearchInput(e.currentTarget.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault();
                                    const isCtrlMeta = e.ctrlKey || e.metaKey;
                                    runSearch(isCtrlMeta, isCtrlMeta);
                                }
                            }}
                        />
                        <MultiSelect
                            data={groupOptions}
                            value={selectedGroupIds}
                            onChange={setSelectedGroupIds}
                            placeholder={t('Select groups')}
                            searchable
                            disabled={isLoadingGroups}
                            rightSection={isLoadingGroups ? <Loader size="xs" /> : null}
                        />
                        <Button
                            onClick={() => runSearch()}
                            disabled={!canSearch || isSearching}
                            loading={isSearching}
                            w="fit-content"
                        >
                            {t('Search')}
                        </Button>
                    </Stack>

                    {shiftHours !== undefined && (
                        <Text size="xs" c="dimmed">
                            {t(
                                'This shift: {{hours}}. Weekly hours are shown as current → after adding this shift / limit.',
                                { hours: formatHours(shiftHours, t) },
                            )}
                        </Text>
                    )}
                    {hasWorkLoadError && (
                        <Text size="xs" c="red">
                            {t('Could not load weekly hours. Check the weekly limit before adding.')}
                        </Text>
                    )}
                    {searchResults !== null && visibleResults.length > 0 && (
                        <Text size="xs" c="dimmed">
                            {t('{{count}} users found', { count: visibleResults.length })}
                        </Text>
                    )}
                    <ScrollArea h={280} style={{ border: '1px solid var(--mantine-color-gray-3)', borderRadius: 4 }}>
                        {searchResults === null ? (
                            <Center h={280}>
                                <Text size="sm" c="dimmed">
                                    {t('No search performed yet')}
                                </Text>
                            </Center>
                        ) : visibleResults.length === 0 ? (
                            <Center h={280}>
                                <Text size="sm" c="dimmed">
                                    {t('No results')}
                                </Text>
                            </Center>
                        ) : (
                            <Stack gap={0} p={4}>
                                {visibleResults.map((user) => (
                                    <UserRow
                                        key={user.id}
                                        user={user}
                                        workLoad={workLoads[user.id.toString()]}
                                        actionType="add"
                                        onAction={handleAdd}
                                    />
                                ))}
                            </Stack>
                        )}
                    </ScrollArea>
                </Stack>
                <Divider orientation="vertical" visibleFrom="md" />
                <Divider orientation="horizontal" hiddenFrom="md" />
                <Stack gap="xs" w={{ base: '100%', md: '50%' }} justify="space-between">
                    <Box style={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column' }}>
                        <MantineGroup justify="space-between">
                            <Text size="sm" fw={500}>
                                {t('Users to be added')}
                            </Text>
                            <Text size="xs" c="dimmed">
                                {selectedUsers.length}
                            </Text>
                        </MantineGroup>
                        <ScrollArea
                            style={{
                                flex: 1,
                                minHeight: 0,
                                marginTop: 4,
                                border: '1px solid var(--mantine-color-gray-3)',
                                borderRadius: 4,
                            }}
                        >
                            {selectedUsers.length === 0 ? (
                                <Center h={280}>
                                    <Text size="sm" c="dimmed">
                                        {t('No users selected')}
                                    </Text>
                                </Center>
                            ) : (
                                <Stack gap={0} p={4}>
                                    {selectedUsers.map((user) => (
                                        <UserRow
                                            key={user.id}
                                            user={user}
                                            workLoad={workLoads[user.id.toString()]}
                                            actionType="remove"
                                            onAction={handleRemove}
                                        />
                                    ))}
                                </Stack>
                            )}
                        </ScrollArea>
                    </Box>
                    <Switch
                        label={isFixed ? t('Fixed') : t('Temp')}
                        checked={isFixed}
                        onChange={(e) => setIsFixed(e.currentTarget.checked)}
                    />
                </Stack>
            </Flex>
            <Flex justify="end" mt={16} gap={8}>
                <Button variant="filled" color="gray" onClick={closeHandler}>
                    {t('Cancel')}
                </Button>
                <Button
                    variant="filled"
                    color="teal"
                    leftSection={<LuSave />}
                    disabled={selectedUsers.length === 0}
                    onClick={() => {
                        onAddUser(selectedUsers, isFixed);
                        closeHandler();
                    }}
                >
                    {t('Save')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default AddUserModal;
