import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuArrowRightLeft,
    LuCircleAlert,
    LuFileWarning,
    LuMessageCircle,
    LuPencilLine,
    LuPlus,
    LuSave,
    LuSearch,
    LuX,
    LuZap,
} from 'react-icons/lu';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Badge,
    Box,
    Button,
    Card,
    CardSection,
    Flex,
    Input,
    Menu,
    Modal,
    NumberInput,
    SegmentedControl,
    Switch,
    Text,
    TextInput,
    Tooltip,
} from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDebouncedValue } from '@mantine/hooks';
import { useAtom, useAtomValue } from 'jotai';
import { User, UserWithAbsenceCount } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { ShiftStatusEnum } from '@/enums/shift_status.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import { getPaperIncompleteWarning } from '@/enums/user_paper_status.enum';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DDataTable, { ColumnDefinition, headerWrapClassName } from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { useFindDirectConversationMutation } from '@/components/chat/api';
import { authAtom, chatIntentAtom } from '@/pages/global.state';
import AbsenceWarning from '@/pages/manage/shift/partials/absence_warning';
import {
    AttendanceFilter,
    AttendanceScope,
    parseAttendanceGroup,
} from '@/pages/manage/shift/partials/attendance_filter';
import getShiftActionErrorMessage from '@/pages/manage/shift/partials/shift_action_error';
import ShiftCheckInOutModal from '@/pages/manage/shift/partials/shift_check_in_out_modal';
import { UserShiftTableMeta } from '@/pages/manage/shift/partials/update/useShiftDetail';
import {
    WeeklyWorkLoadBar,
    isAssignmentBlocked,
    isOverLimitAfterApproval,
    isPositionTarget,
} from '@/pages/manage/shift/partials/weekly_work_load';
import { usePermissions } from '@/hooks/usePermission';
import { ShiftWeeklyWorkLoad, useUpdateAllowOvertimeMutation, useUpdateSalaryMutation } from '@/pages/manage/shift/api';
import { paperStepLabel } from '@/utils/paper_step_label';

const ASSIGNMENT_TABLE_HEIGHT = '28rem';
const ASSIGNMENT_TABLE_SCROLL_THRESHOLD = 8;
const ASSIGNMENT_TABLE_EMPTY_MIN_HEIGHT = 240;

const isRejectedTarget = (groupId: string) => groupId === 'rejected';
const ASSIGNMENT_TABLE_COLUMNS_KEY = 'shift-detail-assignment-table';

export type { AttendanceFilter, AttendanceScope } from '@/pages/manage/shift/partials/attendance_filter';
export {
    parseAttendanceFilter,
    parseAttendanceGroup,
    resolveInitialAttendanceFilter,
} from '@/pages/manage/shift/partials/attendance_filter';

type UserRow = {
    id: string;
    user: UserWithAbsenceCount;
    isFixed: boolean;
    fixedSchedulePosition: string | null;
    hasAbsentNotice: boolean;
    isAbsent: boolean;
    isUrgentRegistration: boolean;
    status: string;
    positionId?: string;
    positionName?: string;
    positionSortOrder: number;
    originalIndex: number;
    phone: string | null;
    shiftUserId: number | null;
    salaryPerHour: number | null;
    isOvertimeAllowed: boolean | null;
    confirmedAt: string | null;
    checkInStart: string | null;
    checkInEnd: string | null;
    positionSalary: number | null;
    leaderBonus: number;
    searchText: string;
};

type UserPositionMeta = {
    groupId: string;
    name: string;
    sortOrder: number | null;
};

type Props = {
    shiftId: string;
    shiftDate: string;
    shiftStatus: string;
    groupId: string;
    // Decoupled from groupId: groupId identifies the move/bulk-assign target (e.g. a raw
    // position id, which changes with the split-by-position/combined view toggle), while
    // attendanceScope is the stable identity used to scope the ?attendance= URL param.
    attendanceScope: AttendanceScope;
    title: string;
    userIds: string[];
    users: Record<string, UserWithAbsenceCount>;
    userIsFixed: Record<string, boolean>;
    userFixedSchedulePosition?: Record<string, string | null>;
    userHasAbsentNotice: Record<string, boolean>;
    userStatus: Record<string, string>;
    shiftSalary: number;
    leaderBonusByUserId: Record<string, number>;
    userPhoneNumbers: Record<string, string | null>;
    userShiftMeta: Record<string, UserShiftTableMeta>;
    onUserShiftMetaChange: (userId: string, values: Partial<UserShiftTableMeta>) => void;
    positions: WorkplacePosition[];
    onMove: (userIds: string[], targetGroup: string) => void;
    onRemove?: (userIds: string[]) => void;
    onBulkAssign?: (userIds: string[], fromGroupId: string, toGroupId: string) => void;
    onAddUsers?: () => void;
    errors?: Record<string, string[]>;
    isReadOnly: boolean;
    resetKey?: number;
    userPositionMeta?: Record<string, UserPositionMeta>;
    showPositionColumn?: boolean;
    initialAttendanceFilter?: AttendanceFilter;
    weeklyWorkLoads?: Record<string, ShiftWeeklyWorkLoad>;
};

type AssignmentTableDataProps = {
    columns: ColumnDefinition<UserRow>[];
    data: UserRow[];
    height?: string;
    isReadOnly: boolean;
    selectedRecords: UserRow[];
    setSelectedRecords: React.Dispatch<React.SetStateAction<UserRow[]>>;
    rowStyle: (record: UserRow) => React.CSSProperties | undefined;
};

type EditableSalaryUser = {
    id: number;
    userId: string;
    salaryPerHour: number | null;
};

const AssignmentTableData = React.memo(
    ({
        columns,
        data,
        height,
        isReadOnly,
        selectedRecords,
        setSelectedRecords,
        rowStyle,
    }: AssignmentTableDataProps) => (
        <DDataTable
            tableKey={ASSIGNMENT_TABLE_COLUMNS_KEY}
            data={data}
            isFetching={false}
            height={height}
            emptyMinHeight={ASSIGNMENT_TABLE_EMPTY_MIN_HEIGHT}
            selectedRecords={isReadOnly ? undefined : selectedRecords}
            onSelectedRecordsChange={isReadOnly ? undefined : setSelectedRecords}
            rowStyle={rowStyle}
            columns={columns}
        />
    ),
);

AssignmentTableData.displayName = 'AssignmentTableData';

const AssignmentTableUpdateSalaryModal = ({
    shiftId,
    shiftUser,
    onClose,
    onUpdated,
}: {
    shiftId: string;
    shiftUser: EditableSalaryUser | null;
    onClose: () => void;
    onUpdated: (userId: string, salaryPerHour: number | null) => void;
}) => {
    const { t } = useTranslation();
    const updateSalary = useUpdateSalaryMutation(shiftId);
    const form = useForm<{ salary_per_hour: number | null }>({
        initialValues: {
            salary_per_hour: null,
        },
    });
    const currentSalaryPerHour = shiftUser?.salaryPerHour ?? null;
    const currentShiftUserId = shiftUser?.id ?? null;

    useEffect(() => {
        form.setValues({
            salary_per_hour: currentSalaryPerHour,
        });
        form.resetDirty();
    }, [currentSalaryPerHour, currentShiftUserId]);

    const handleSubmit = async (values: { salary_per_hour: number | null }) => {
        if (!shiftUser) return;

        try {
            await updateSalary.mutateAsync({
                shiftUserId: shiftUser.id,
                salary: values.salary_per_hour,
            });
            onUpdated(shiftUser.userId, values.salary_per_hour);
            NotificationHelper.showSuccess(t('Successfully updated user salary'));
            onClose();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                FormHelper.setFormError(form, error);
                return;
            }
            NotificationHelper.showError(getShiftActionErrorMessage(t, error, t('error|Could not update user salary')));
        }
    };

    return (
        <Modal opened={shiftUser !== null} onClose={onClose} title={t('Update user salary')}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <NumberInput label={t('Salary per hour')} {...form.getInputProps('salary_per_hour')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" color="teal" leftSection={<LuSave />} loading={updateSalary.isPending}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

const AbsentShiftButton = ({ label, tooltipLabel }: { label: string; tooltipLabel: string }) => {
    const [opened, setOpened] = useState(false);
    return (
        <Tooltip label={tooltipLabel} opened={opened} withArrow>
            <Box
                style={{ display: 'inline-block', cursor: 'not-allowed' }}
                onClick={() => setOpened((o) => !o)}
                onMouseLeave={() => setOpened(false)}
            >
                <Button size="xs" h="26px" disabled style={{ pointerEvents: 'none' }}>
                    {label}
                </Button>
            </Box>
        </Tooltip>
    );
};

const MessageActionButton = ({ onClick, loading }: { onClick: () => void; loading: boolean }) => {
    const { t } = useTranslation();

    return (
        <ActionIcon
            variant="subtle"
            color="blue"
            onClick={onClick}
            loading={loading}
            title={t('Message')}
            aria-label={t('Message')}
        >
            <LuMessageCircle size={16} />
        </ActionIcon>
    );
};

const AbsentShiftTime = ({ value, tooltipLabel }: { value: string; tooltipLabel: string }) => {
    const { t } = useTranslation();
    return (
        <Tooltip label={tooltipLabel} withArrow>
            <Text size="sm" c="dimmed" td="line-through" style={{ cursor: 'not-allowed' }}>
                {DateHelper.parseAndFormat(value, DATE_STORE_FORMAT_WITH_TIME, t('FORMAT_TIMESTAMP')) || '-'}
            </Text>
        </Tooltip>
    );
};

const AssignmentTable = ({
    shiftId,
    shiftDate,
    shiftStatus,
    groupId,
    attendanceScope,
    title,
    userIds,
    users,
    userIsFixed,
    userFixedSchedulePosition,
    userHasAbsentNotice,
    userStatus,
    shiftSalary,
    leaderBonusByUserId,
    userPhoneNumbers,
    userShiftMeta,
    onUserShiftMetaChange,
    positions,
    onMove,
    onRemove,
    onBulkAssign,
    onAddUsers,
    errors,
    isReadOnly,
    resetKey,
    userPositionMeta,
    showPositionColumn = false,
    initialAttendanceFilter,
    weeklyWorkLoads,
}: Props) => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions([
        'SHIFT_CHECK_IN',
        'SHIFT_UPDATE_OVERTIME',
        'SHIFT_UPDATE_SALARY',
        'CHAT_CREATE',
        'CHAT_VIEW',
    ]);
    // The lookup endpoint and AdminChatPortal both require chat.view in addition to
    // chat.create, so the shortcut must gate on both to avoid a button that always 403s
    // for a role that has chat.create without chat.view.
    const canMessage = perms.CHAT_CREATE && perms.CHAT_VIEW;
    const auth = useAtomValue(authAtom);
    const updateAllowOvertime = useUpdateAllowOvertimeMutation(shiftId);
    const [, setChatIntent] = useAtom(chatIntentAtom);
    const findDirectConversation = useFindDirectConversationMutation();
    const [activeMessageUserId, setActiveMessageUserId] = useState<number | null>(null);
    const [selectedRecords, setSelectedRecords] = useState<UserRow[]>([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [attendanceFilter, setAttendanceFilter] = useState<AttendanceFilter>(initialAttendanceFilter ?? 'all');
    const [updateSalaryUser, setUpdateSalaryUser] = useState<EditableSalaryUser | null>(null);
    const [checkInOutShiftUserId, setCheckInOutShiftUserId] = useState<string | null>(null);
    const [isCheckIn, setIsCheckIn] = useState(true);
    const [searchQueryDebounced] = useDebouncedValue(searchQuery, 300);

    const handleMessageClick = useCallback(
        async (employee: User) => {
            setActiveMessageUserId(employee.id);
            try {
                const result = await findDirectConversation.mutateAsync(employee.id);
                const conversationId = result.data?.conversation_id ?? null;
                if (conversationId) {
                    setChatIntent({ kind: 'open', conversationId });
                } else {
                    setChatIntent({
                        kind: 'create-direct',
                        user: employee,
                        defaultTitle: t('Chat with {{name}}', { name: employee.name }),
                    });
                }
            } catch (e) {
                console.error(e);
                NotificationHelper.showError(t('error|Could not open chat'));
            } finally {
                setActiveMessageUserId(null);
            }
        },
        [findDirectConversation, setChatIntent, t],
    );

    // Clear all selections when resetKey changes
    useEffect(() => {
        setSelectedRecords([]);
    }, [resetKey]);

    // Re-sync from the URL when it changes, not just on first mount: this component instance
    // is reused (not remounted) across a client-side navigation between two shift detail pages
    // (e.g. a next/prev shift link), so a useState initializer alone would miss the new value.
    //
    // Navigating to a *different* shift always applies initialAttendanceFilter (defaulting to
    // 'all' when the new URL doesn't target this table), even though resolveInitialAttendanceFilter
    // itself can't tell "no scoped value for this table" apart from "no value at all" — otherwise
    // a clean URL for the new shift would leave the previous shift's filter on screen. Within the
    // *same* shift, a sibling table's write only updates this table when it's the one addressed
    // (initialAttendanceFilter !== undefined), so unrelated tables keep their own local filter.
    const previousShiftIdRef = useRef(shiftId);
    useEffect(() => {
        const isNewShift = previousShiftIdRef.current !== shiftId;
        previousShiftIdRef.current = shiftId;

        if (isNewShift) {
            setAttendanceFilter(initialAttendanceFilter ?? 'all');
            return;
        }
        if (initialAttendanceFilter !== undefined) {
            setAttendanceFilter(initialAttendanceFilter);
        }
    }, [shiftId, initialAttendanceFilter]);

    const records: UserRow[] = useMemo(() => {
        const rows: UserRow[] = [];
        userIds.forEach((id, index) => {
            const user = users[id];
            if (!user) return;
            const positionMeta = userPositionMeta?.[id];
            const shiftMeta = userShiftMeta[id];
            const phone = userPhoneNumbers[id] ?? null;

            rows.push({
                id,
                user,
                isFixed: userIsFixed[id] ?? true,
                fixedSchedulePosition: userFixedSchedulePosition?.[id] ?? null,
                hasAbsentNotice: userHasAbsentNotice[id] ?? false,
                isAbsent: shiftMeta?.isAbsent ?? false,
                isUrgentRegistration: shiftMeta?.isUrgentRegistration ?? false,
                status: userStatus[id] ?? '',
                positionId: positionMeta?.groupId,
                positionName: positionMeta?.name,
                positionSortOrder: positionMeta?.sortOrder ?? Number.MAX_SAFE_INTEGER,
                originalIndex: index,
                phone,
                shiftUserId: shiftMeta?.shiftUserId ?? null,
                salaryPerHour: shiftMeta?.salaryPerHour ?? null,
                isOvertimeAllowed: shiftMeta?.isOvertimeAllowed ?? null,
                confirmedAt: shiftMeta?.confirmedAt ?? null,
                checkInStart: shiftMeta?.checkInStart ?? null,
                checkInEnd: shiftMeta?.checkInEnd ?? null,
                positionSalary: shiftMeta?.workplacePositionSalary ?? null,
                leaderBonus: leaderBonusByUserId[id] ?? 0,
                searchText: [user.name, user.email, user.staff_no, phone].filter(Boolean).join(' ').toLowerCase(),
            });
        });

        rows.sort((a, b) => {
            if (a.isFixed !== b.isFixed) {
                return a.isFixed ? -1 : 1;
            }

            if (a.positionSortOrder !== b.positionSortOrder) {
                return a.positionSortOrder - b.positionSortOrder;
            }

            return a.originalIndex - b.originalIndex;
        });
        return rows;
    }, [
        leaderBonusByUserId,
        userHasAbsentNotice,
        userIds,
        userIsFixed,
        userFixedSchedulePosition,
        userPhoneNumbers,
        userPositionMeta,
        userShiftMeta,
        userStatus,
        users,
    ]);

    const filteredRecords: UserRow[] = useMemo(() => {
        const query = searchQueryDebounced.trim().toLowerCase();
        return records.filter((record) => {
            if (query && !record.searchText.includes(query)) {
                return false;
            }
            if (attendanceFilter === 'unconfirmed' && record.confirmedAt) {
                return false;
            }
            if (attendanceFilter === 'not_checked_in' && record.checkInStart) {
                return false;
            }
            return true;
        });
    }, [records, searchQueryDebounced, attendanceFilter]);

    // Remove selected records that are no longer in this group, or hidden by the search/attendance filter
    useEffect(() => {
        setSelectedRecords((prev) => {
            const visibleIdSet = new Set(filteredRecords.map((r) => r.id));
            const filtered = prev.filter((r) => visibleIdSet.has(r.id));
            return filtered.length === prev.length ? prev : filtered;
        });
    }, [filteredRecords]);

    const fixedCount = useMemo(() => filteredRecords.filter((r) => r.isFixed).length, [filteredRecords]);
    const tempCount = filteredRecords.length - fixedCount;
    const tableHeight =
        filteredRecords.length > ASSIGNMENT_TABLE_SCROLL_THRESHOLD ? ASSIGNMENT_TABLE_HEIGHT : undefined;

    const bulkTargetOptions = useMemo(() => {
        const options: { value: string; label: string }[] = [];
        for (const position of positions) {
            if (position.id.toString() !== groupId) {
                options.push({ value: position.id.toString(), label: position.name });
            }
        }
        if (groupId !== 'pending') {
            options.push({ value: 'pending', label: t('Register list') });
        }
        if (groupId !== 'rejected') {
            options.push({ value: 'rejected', label: t('Rejected list') });
        }
        return options;
    }, [positions, groupId, t]);

    const errorUserIds = useMemo(() => {
        if (!errors) return new Set<string>();
        return new Set(Object.keys(errors));
    }, [errors]);

    const errorMessages = useMemo(() => {
        if (!errors) return [];
        const messages: string[] = [];
        for (const userId in errors) {
            const userName = users[userId]?.name || userId;
            for (const msg of errors[userId]) {
                messages.push(`${userName}: ${msg}`);
            }
        }
        return messages;
    }, [errors, users]);

    const handleAttendanceFilterChange = useCallback(
        (value: AttendanceFilter) => {
            setAttendanceFilter(value);

            // Mirror the selection into ?attendance=&attendanceGroup= via shallow navigation so
            // the URL stays shareable. The page renders several AssignmentTable instances that
            // would all read the same ?attendance= value; tagging the write with this table's
            // own attendanceScope lets the page seed initialAttendanceFilter for only the
            // matching table (see resolveInitialAttendanceFilter and [id].page.tsx), so changing
            // the filter here doesn't force an unrelated table to re-filter and silently drop
            // its bulk-assign selection.
            const query = { ...router.query };
            if (value === 'all') {
                // Only clear the pair when it currently belongs to this table's own scope.
                // Otherwise it's either a bare/global link or another table's write, and
                // resetting this table must not blow that away — a copied link would then no
                // longer reproduce the other table's still-active filter (round-trip finding).
                if (parseAttendanceGroup(query.attendanceGroup) !== attendanceScope) {
                    return;
                }
                delete query.attendance;
                delete query.attendanceGroup;
            } else {
                query.attendance = value;
                query.attendanceGroup = attendanceScope;
            }
            router.replace({ pathname: router.pathname, query }, undefined, { shallow: true });
        },
        [router, attendanceScope],
    );

    const handleBulkSelect = (targetGroupId: string) => {
        if (selectedRecords.length === 0) return;
        if (onBulkAssign) {
            onBulkAssign(
                selectedRecords.map((r) => r.id),
                groupId,
                targetGroupId,
            );
        } else {
            onMove(
                selectedRecords.map((r) => r.id),
                targetGroupId,
            );
        }
        setSelectedRecords([]);
    };

    const getSalaryInfo = useCallback(
        (record: UserRow) => {
            if (record.shiftUserId === null) {
                return {
                    amount: null,
                    isCustomSalary: false,
                    isLeader: false,
                };
            }

            let amount = shiftSalary;
            let isCustomSalary = false;
            let isLeader = false;

            if (record.positionSalary !== null) {
                amount = record.positionSalary;
            }
            if (record.leaderBonus > 0) {
                isLeader = true;
                amount += record.leaderBonus;
            }
            if (record.salaryPerHour !== null) {
                amount = record.salaryPerHour;
                isCustomSalary = true;
            }

            return {
                amount,
                isCustomSalary,
                isLeader,
            };
        },
        [shiftSalary],
    );

    const handleOvertimeChange = useCallback(
        async (record: UserRow, isAllowed: boolean) => {
            if (record.shiftUserId === null) return;

            try {
                await updateAllowOvertime.mutateAsync({
                    shiftUserId: record.shiftUserId,
                    isAllowed: isAllowed ? 1 : 0,
                });
            } catch (error) {
                // Without this the switch flips back with no explanation and the rejected
                // promise goes unhandled - there is no global MutationCache onError.
                NotificationHelper.showError(
                    getShiftActionErrorMessage(t, error, t('error|Could not update overtime status')),
                );

                return;
            }

            onUserShiftMetaChange(record.id, { isOvertimeAllowed: isAllowed });
            NotificationHelper.showSuccess(t('Successfully update overtime status'));
        },
        [onUserShiftMetaChange, t, updateAllowOvertime],
    );

    const handleCheckInOutSuccess = useCallback(
        (values: { date: string; time: string }, isCheckInAction: boolean) => {
            const currentShiftUserId = Number(checkInOutShiftUserId);
            const submittedAt =
                DateHelper.parse(`${values.date} ${values.time}`, null, false)?.format(DATE_STORE_FORMAT_WITH_TIME) ??
                `${values.date} ${values.time.length === 5 ? `${values.time}:00` : values.time}`;

            const matchedRecord = records.find((record) => record.shiftUserId === currentShiftUserId);
            if (!matchedRecord) {
                return;
            }

            onUserShiftMetaChange(matchedRecord.id, {
                ...(isCheckInAction ? { checkInStart: submittedAt } : { checkInEnd: submittedAt }),
            });
        },
        [checkInOutShiftUserId, onUserShiftMetaChange, records],
    );

    const checkTimeFormat = `${t('FORMAT_DATE_SHORT')} HH:mm`;
    const tableColumns = useMemo<ColumnDefinition<UserRow>[]>(
        () => [
            {
                key: 'actions',
                name: '',
                width: 130,
                render: (record: UserRow) => {
                    if (isReadOnly) return null;
                    const isUnpersisted = record.shiftUserId === null;
                    const workLoad = weeklyWorkLoads?.[record.id];
                    const isBlocked = isAssignmentBlocked(workLoad);
                    const isOverAfterApproval = isOverLimitAfterApproval(workLoad);
                    return (
                        <Flex gap={4} align="center">
                            <Menu trigger="hover" openDelay={100} closeDelay={200} position="right-start" withArrow>
                                <Menu.Target>
                                    <Button
                                        variant="subtle"
                                        size="compact-sm"
                                        color={isBlocked || isOverAfterApproval ? 'red' : undefined}
                                        leftSection={<LuArrowRightLeft size={12} />}
                                    >
                                        {t('Assign')}
                                    </Button>
                                </Menu.Target>
                                <Menu.Dropdown>
                                    {isBlocked && (
                                        <Menu.Label c="red" maw={260} style={{ whiteSpace: 'normal' }}>
                                            {t(
                                                'Over the weekly work-hour limit: can only be moved to the rejected list',
                                            )}
                                        </Menu.Label>
                                    )}
                                    {isOverAfterApproval && (
                                        <Menu.Label c="red" maw={260} style={{ whiteSpace: 'normal' }}>
                                            {t(
                                                'Approved before going over the weekly work-hour limit: move to the register or rejected list to reduce hours',
                                            )}
                                        </Menu.Label>
                                    )}
                                    {bulkTargetOptions.map((option) => (
                                        <Menu.Item
                                            key={option.value}
                                            disabled={
                                                (isBlocked && !isRejectedTarget(option.value)) ||
                                                (isOverAfterApproval && isPositionTarget(option.value))
                                            }
                                            onClick={() => onMove([record.id], option.value)}
                                        >
                                            {option.label}
                                        </Menu.Item>
                                    ))}
                                </Menu.Dropdown>
                            </Menu>
                            {isUnpersisted && onRemove && (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    size="sm"
                                    onClick={() => onRemove([record.id])}
                                    title={t('Remove')}
                                >
                                    <LuX size={14} />
                                </ActionIcon>
                            )}
                        </Flex>
                    );
                },
            },
            ...(canMessage
                ? [
                      {
                          key: 'message',
                          name: '',
                          width: 44,
                          render: (record: UserRow) => {
                              // A 1:1 chat with yourself isn't a real conversation: the lookup's
                              // two participant clauses would collapse into one and could open
                              // an unrelated existing chat instead (see
                              // FindDirectConversationAction). The admin may also appear on the
                              // roster as an assigned employee, so guard the row rather than
                              // assuming this case can't happen.
                              if (record.user.id === auth?.user.id) {
                                  return null;
                              }
                              return (
                                  <MessageActionButton
                                      onClick={() => handleMessageClick(record.user)}
                                      loading={activeMessageUserId === record.user.id}
                                  />
                              );
                          },
                      } satisfies ColumnDefinition<UserRow>,
                  ]
                : []),
            {
                key: 'user.id',
                name: t('Name'),
                width: 250,
                render: (data) => {
                    const paperIncompleteWarning = getPaperIncompleteWarning(data.user.paper_status, t);
                    const outstandingSteps = data.user.outstanding_document_steps ?? [];
                    const paperIncompleteTooltip =
                        paperIncompleteWarning && outstandingSteps.length > 0
                            ? `${paperIncompleteWarning}: ${outstandingSteps.map((step) => paperStepLabel(step, t)).join(', ')}`
                            : paperIncompleteWarning;

                    return (
                        <Flex align="center" gap="xs">
                            <UserWithAvatar user={data.user} />
                            <AbsenceWarning absenceCount={data.user.absence_count} />
                            {paperIncompleteTooltip && (
                                <Tooltip label={paperIncompleteTooltip} withArrow multiline maw={320}>
                                    <Box
                                        c="yellow.7"
                                        aria-label={paperIncompleteTooltip}
                                        style={{ display: 'flex', flexShrink: 0 }}
                                    >
                                        <LuFileWarning size={16} />
                                    </Box>
                                </Tooltip>
                            )}
                            {data.isUrgentRegistration && (
                                <Badge size="xs" variant="light" color="orange" leftSection={<LuZap size={12} />}>
                                    {t('Urgent')}
                                </Badge>
                            )}
                            {data.hasAbsentNotice && (
                                <Badge size="xs" variant="light" color="red" leftSection={<LuCircleAlert size={12} />}>
                                    {t('Absent Notice')}
                                </Badge>
                            )}
                        </Flex>
                    );
                },
            },
            {
                key: 'weeklyWorkLoad',
                name: t('Weekly hours'),
                width: 170,
                titleClassName: headerWrapClassName,
                toggleable: true,
                render: (record) => <WeeklyWorkLoadBar workLoad={weeklyWorkLoads?.[record.id]} />,
            },
            ...(showPositionColumn
                ? [
                      {
                          key: 'positionName',
                          name: t('Position'),
                          width: 180,
                          toggleable: true,
                          render: (record: UserRow) => record.positionName || '-',
                      } satisfies ColumnDefinition<UserRow>,
                  ]
                : []),
            {
                key: 'isFixed',
                name: t('Type'),
                width: 80,
                toggleable: true,
                render: (record) => (
                    <Badge size="xs" variant="light" color={record.isFixed ? 'gray' : 'blue'}>
                        {record.isFixed ? t('Fixed') : t('Temp')}
                    </Badge>
                ),
            },
            {
                key: 'fixedSchedulePosition',
                name: t('Fixed schedule position'),
                width: 160,
                toggleable: true,
                titleClassName: headerWrapClassName,
                render: (record) => record.fixedSchedulePosition || '-',
            },
            {
                key: 'phone',
                name: t('Phone'),
                width: 140,
                toggleable: true,
                render: (record) => record.phone || '-',
            },
            {
                key: 'salaryPerHour',
                name: t('Salary per hour'),
                width: 100,
                titleClassName: headerWrapClassName,
                toggleable: true,
                render: (record) => {
                    const salaryInfo = getSalaryInfo(record);
                    if (salaryInfo.amount === null) {
                        return '-';
                    }

                    return (
                        <Flex gap={8} align="center">
                            <Text
                                fw={salaryInfo.isCustomSalary ? 700 : 400}
                                c={salaryInfo.isCustomSalary ? 'red' : undefined}
                            >
                                {salaryInfo.amount} {salaryInfo.isLeader && !salaryInfo.isCustomSalary ? '★' : ''}
                            </Text>
                            <ActionIcon
                                variant="subtle"
                                color="blue"
                                onClick={() =>
                                    setUpdateSalaryUser({
                                        id: record.shiftUserId!,
                                        userId: record.id,
                                        salaryPerHour: record.salaryPerHour,
                                    })
                                }
                                disabled={!perms.SHIFT_UPDATE_SALARY}
                            >
                                <LuPencilLine size={16} />
                            </ActionIcon>
                        </Flex>
                    );
                },
            },
            {
                key: 'confirmedAt',
                name: t('Confirmed'),
                width: 170,
                toggleable: true,
                render: (record) =>
                    DateHelper.parseAndFormat(record.confirmedAt, DATE_STORE_FORMAT_WITH_TIME, t('FORMAT_TIMESTAMP')) ||
                    '-',
            },
            {
                key: 'isOvertimeAllowed',
                name: t('Overtime allowed'),
                width: 120,
                titleClassName: headerWrapClassName,
                toggleable: true,
                render: (record) => {
                    if (record.isOvertimeAllowed === null) {
                        return '-';
                    }

                    return (
                        <Tooltip label={t('Shift marked as absent')} withArrow disabled={!record.isAbsent}>
                            <Box style={{ display: 'inline-block' }}>
                                <Switch
                                    checked={record.isOvertimeAllowed}
                                    label={record.isOvertimeAllowed ? t('Yes') : t('No')}
                                    disabled={
                                        record.isAbsent || !perms.SHIFT_UPDATE_OVERTIME || updateAllowOvertime.isPending
                                    }
                                    style={record.isAbsent ? { pointerEvents: 'none' } : undefined}
                                    onChange={(event) => handleOvertimeChange(record, event.currentTarget.checked)}
                                />
                            </Box>
                        </Tooltip>
                    );
                },
            },
            {
                key: 'checkInStart',
                name: t('Start time'),
                width: 125,
                titleClassName: headerWrapClassName,
                toggleable: true,
                render: (record) => {
                    if (record.isAbsent) {
                        return record.checkInStart ? (
                            <AbsentShiftTime value={record.checkInStart} tooltipLabel={t('Shift marked as absent')} />
                        ) : (
                            <AbsentShiftButton label={t('Check in')} tooltipLabel={t('Shift marked as absent')} />
                        );
                    }

                    if (record.checkInStart) {
                        return (
                            DateHelper.parseAndFormat(
                                record.checkInStart,
                                DATE_STORE_FORMAT_WITH_TIME,
                                checkTimeFormat,
                            ) || '-'
                        );
                    }

                    if (
                        record.status !== ShiftUserStatusEnum.APPROVED.value ||
                        shiftStatus === ShiftStatusEnum.COMING_SOON.value ||
                        record.shiftUserId === null
                    ) {
                        return shiftStatus === ShiftStatusEnum.COMING_SOON.value &&
                            record.status === ShiftUserStatusEnum.APPROVED.value
                            ? t('Not yet')
                            : '-';
                    }

                    return (
                        <Button
                            size="xs"
                            h="26px"
                            onClick={() => {
                                setCheckInOutShiftUserId(record.shiftUserId!.toString());
                                setIsCheckIn(true);
                            }}
                            disabled={!perms.SHIFT_CHECK_IN}
                        >
                            {t('Check in')}
                        </Button>
                    );
                },
            },
            {
                key: 'checkInEnd',
                name: t('End time'),
                width: 125,
                titleClassName: headerWrapClassName,
                toggleable: true,
                render: (record) => {
                    if (record.isAbsent) {
                        return record.checkInEnd ? (
                            <AbsentShiftTime value={record.checkInEnd} tooltipLabel={t('Shift marked as absent')} />
                        ) : (
                            <AbsentShiftButton label={t('Check out')} tooltipLabel={t('Shift marked as absent')} />
                        );
                    }

                    if (record.checkInEnd) {
                        return (
                            DateHelper.parseAndFormat(
                                record.checkInEnd,
                                DATE_STORE_FORMAT_WITH_TIME,
                                checkTimeFormat,
                            ) || '-'
                        );
                    }

                    if (record.status !== ShiftUserStatusEnum.APPROVED.value || record.shiftUserId === null) {
                        return '-';
                    }

                    if (!record.checkInStart) {
                        return t('Not yet checked in');
                    }

                    if (shiftStatus !== ShiftStatusEnum.ENDED.value) {
                        return t('Not yet');
                    }

                    return (
                        <Button
                            size="xs"
                            h="26px"
                            onClick={() => {
                                setCheckInOutShiftUserId(record.shiftUserId!.toString());
                                setIsCheckIn(false);
                            }}
                            disabled={!perms.SHIFT_CHECK_IN}
                        >
                            {t('Check out')}
                        </Button>
                    );
                },
            },
        ],
        [
            activeMessageUserId,
            auth?.user.id,
            bulkTargetOptions,
            handleCheckInOutSuccess,
            handleMessageClick,
            getSalaryInfo,
            handleOvertimeChange,
            isReadOnly,
            onMove,
            canMessage,
            perms.SHIFT_CHECK_IN,
            perms.SHIFT_UPDATE_OVERTIME,
            perms.SHIFT_UPDATE_SALARY,
            onRemove,
            showPositionColumn,
            shiftStatus,
            t,
            updateAllowOvertime.isPending,
            weeklyWorkLoads,
            checkTimeFormat,
        ],
    );

    const rowStyle = useCallback(
        (record: UserRow): React.CSSProperties | undefined => {
            if (errorUserIds.has(record.id)) {
                return { backgroundColor: 'var(--mantine-color-red-1)' };
            }
            if (record.status === ShiftUserStatusEnum.APPROVED.value && record.hasAbsentNotice) {
                return { backgroundColor: 'var(--mantine-color-red-1)' };
            }
            if (record.isFixed) {
                return { backgroundColor: 'var(--mantine-color-teal-0)' };
            }
            return undefined;
        },
        [errorUserIds],
    );

    return (
        <Card withBorder mb="md">
            <CardSection withBorder p="md">
                <Flex justify="space-between" align="center">
                    <Flex align="center" gap="xs">
                        <Text fw={700}>{title}</Text>
                        <Badge size="sm" variant="light" color="teal">
                            {t('Fixed')}: {fixedCount}
                        </Badge>
                        <Badge size="sm" variant="light" color="blue">
                            {t('Temp')}: {tempCount}
                        </Badge>
                    </Flex>
                    <Flex gap={8} align="center">
                        <SegmentedControl
                            size="xs"
                            value={attendanceFilter}
                            onChange={(value) => handleAttendanceFilterChange(value as AttendanceFilter)}
                            data={[
                                { value: 'all', label: t('All') },
                                { value: 'unconfirmed', label: t('Not confirmed') },
                                { value: 'not_checked_in', label: t('Not checked in') },
                            ]}
                        />
                        <TextInput
                            placeholder={t('Search...')}
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.currentTarget.value)}
                            size="xs"
                            style={{ width: 200 }}
                            leftSection={<LuSearch size={12} />}
                            rightSection={
                                searchQuery !== '' ? (
                                    <Input.ClearButton onClick={() => setSearchQuery('')} />
                                ) : undefined
                            }
                            rightSectionPointerEvents="auto"
                        />
                        {onAddUsers && !isReadOnly && (
                            <Button variant="outline" size="xs" leftSection={<LuPlus size={12} />} onClick={onAddUsers}>
                                {t('Add')}
                            </Button>
                        )}
                        <Menu>
                            <Menu.Target>
                                <Button
                                    variant="outline"
                                    size="xs"
                                    leftSection={<LuArrowRightLeft size={12} />}
                                    disabled={isReadOnly || selectedRecords.length === 0}
                                >
                                    {t('Assign to...')}
                                </Button>
                            </Menu.Target>
                            <Menu.Dropdown>
                                {bulkTargetOptions.map((option) => (
                                    <Menu.Item key={option.value} onClick={() => handleBulkSelect(option.value)}>
                                        {option.label}
                                    </Menu.Item>
                                ))}
                            </Menu.Dropdown>
                        </Menu>
                    </Flex>
                </Flex>
            </CardSection>
            <CardSection p="md">
                <AssignmentTableData
                    data={filteredRecords}
                    height={tableHeight}
                    isReadOnly={isReadOnly}
                    selectedRecords={selectedRecords}
                    setSelectedRecords={setSelectedRecords}
                    rowStyle={rowStyle}
                    columns={tableColumns}
                />
            </CardSection>

            {errorMessages.length > 0 && (
                <CardSection p="xs" px="md">
                    {errorMessages.map((msg) => (
                        <Text key={msg} size="xs" c="red">
                            {msg}
                        </Text>
                    ))}
                </CardSection>
            )}

            <AssignmentTableUpdateSalaryModal
                shiftId={shiftId}
                shiftUser={updateSalaryUser}
                onClose={() => setUpdateSalaryUser(null)}
                onUpdated={(userId, salaryPerHour) => {
                    onUserShiftMetaChange(userId, { salaryPerHour });
                }}
            />
            <ShiftCheckInOutModal
                shiftId={shiftId}
                date={shiftDate}
                shiftUserId={checkInOutShiftUserId}
                setShiftUserId={setCheckInOutShiftUserId}
                isCheckIn={isCheckIn}
                onSuccess={handleCheckInOutSuccess}
            />
        </Card>
    );
};

export default AssignmentTable;
