import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuClock, LuTrash2, LuUpload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Box, Breadcrumbs, Button, Flex, Loader, Switch, Text, Title } from '@mantine/core';
import { useQueryClient } from '@tanstack/react-query';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import ShiftHelper from '@/helpers/shift.helper';
import AssignmentTable, {
    AttendanceScope,
    resolveInitialAttendanceFilter,
} from '@/pages/manage/shift/partials/assignment_table';
import ActionBar from '@/pages/manage/shift/partials/update/action_bar';
import AddUserModal from '@/pages/manage/shift/partials/update/add_user_modal';
import BulkAssignModal, { BulkAssignRequest } from '@/pages/manage/shift/partials/update/bulk_assign_modal';
import ConfigModal from '@/pages/manage/shift/partials/update/config_modal';
import DeleteShiftModal from '@/pages/manage/shift/partials/update/delete_shift_modal';
import ErrorAlerts from '@/pages/manage/shift/partials/update/error_alerts';
import ModalImportCSV from '@/pages/manage/shift/partials/update/modal_import_csv';
import OvertimeModal from '@/pages/manage/shift/partials/update/overtime_modal';
import ShiftInfoCard from '@/pages/manage/shift/partials/update/shift_info_card';
import { useShiftDetail } from '@/pages/manage/shift/partials/update/useShiftDetail';
import {
    isAssignmentBlocked,
    isOverLimitAfterApproval,
    isPositionTarget,
} from '@/pages/manage/shift/partials/weekly_work_load';
import classes from './[id].module.css';
import { useShiftWeeklyWorkLoadsQuery } from '@/pages/manage/shift/api';

const ASSIGNMENT_VIEW_PREFERENCE_KEY = 'shift-detail-split-by-position-view';

const IndexPage = () => {
    const { t } = useTranslation();
    const {
        id,
        perms,
        isLoading,
        data,
        shift,
        positions,
        hotBonusAmounts,
        isReadOnly,
        isShiftEnded,
        hasChanges,
        selectionResetKey,
        form,
        users,
        userIsFixed,
        userFixedSchedulePosition,
        userHasAbsentNotice,
        userStatus,
        userPhoneNumbers,
        userShiftMeta,
        groupUsers,
        positionErrors,
        csvImportErrorData,
        addToPositionId,
        setAddToPositionId,
        isShowImportModal,
        setIsShowImportModal,
        encoding,
        setEncoding,
        fileInputRef,
        importCsvMutation,
        updateMutation,
        moveUsers,
        removeUsers,
        updateUserShiftMeta,
        handleAddUsers,
        handleSave,
        handleCancel,
        handleCsvUpload,
        handleExport,
    } = useShiftDetail();

    const router = useRouter();
    const queryClient = useQueryClient();
    const workLoadUserIds = useMemo(() => Object.keys(users), [users]);
    const { data: weeklyWorkLoads } = useShiftWeeklyWorkLoadsQuery(id, workLoadUserIds);

    const moveUsersWithinLimit = useCallback(
        (userIds: string[], targetGroupId: string) => {
            if (targetGroupId === 'rejected') {
                moveUsers(userIds, targetGroupId);
                return;
            }
            const blockedUserIds = userIds.filter((userId) => isAssignmentBlocked(weeklyWorkLoads?.[userId]));
            if (blockedUserIds.length > 0) {
                NotificationHelper.showError(
                    t('Cannot assign {{names}}: the weekly work-hour limit would be exceeded', {
                        names: blockedUserIds.map((userId) => users[userId]?.name ?? userId).join(', '),
                    }),
                );
            }
            const overAfterApprovalUserIds = isPositionTarget(targetGroupId)
                ? userIds.filter((userId) => isOverLimitAfterApproval(weeklyWorkLoads?.[userId]))
                : [];
            if (overAfterApprovalUserIds.length > 0) {
                NotificationHelper.showError(
                    t(
                        'Cannot move {{names}} to another position: already over the weekly work-hour limit, move them to the register or rejected list instead',
                        {
                            names: overAfterApprovalUserIds.map((userId) => users[userId]?.name ?? userId).join(', '),
                        },
                    ),
                );
            }
            const allowedUserIds = userIds.filter(
                (userId) => !blockedUserIds.includes(userId) && !overAfterApprovalUserIds.includes(userId),
            );
            if (allowedUserIds.length > 0) {
                moveUsers(allowedUserIds, targetGroupId);
            }
        },
        [moveUsers, t, users, weeklyWorkLoads],
    );
    // This page renders several AssignmentTable instances (register list, per-position or
    // combined approved tables, rejected list) that would all read the same ?attendance=
    // value. Scoping the seed to whichever table's AttendanceScope tagged the URL (via
    // ?attendanceGroup=) keeps a filter change in one table from forcing every other table
    // to re-filter and drop its selection. The approved roster always resolves to the
    // 'approved' scope regardless of isSplitByPositionView, so a link stays valid across
    // that view toggle — see resolveInitialAttendanceFilter for the full matching logic.
    const getInitialAttendanceFilter = useCallback(
        (scope: AttendanceScope) =>
            resolveInitialAttendanceFilter(router.query.attendance, router.query.attendanceGroup, scope),
        [router.query.attendance, router.query.attendanceGroup],
    );
    const [isConfigModalOpen, setIsConfigModalOpen] = useState(false);
    const [isOvertimeModalOpen, setIsOvertimeModalOpen] = useState(false);
    const [isDeleteModalOpen, setIsDeleteModalOpen] = useState(false);
    const [bulkAssignRequest, setBulkAssignRequest] = useState<BulkAssignRequest | null>(null);
    const [isSplitByPositionView, setIsSplitByPositionView] = useState(true);
    const [hasLoadedViewPreference, setHasLoadedViewPreference] = useState(false);
    const errorAlertsRef = useRef<HTMLDivElement>(null);

    const hasFormErrors = !!(
        form.errors['not_member_ids'] ||
        form.errors['disabled_ids'] ||
        form.errors['fixed_total'] ||
        form.errors['temp_total']
    );

    useEffect(() => {
        if (hasFormErrors) {
            errorAlertsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, [hasFormErrors]);

    const handleBulkAssign = useCallback(
        (userIds: string[], fromGroupId: string, toGroupId: string) => {
            setBulkAssignRequest({ userIds, users, fromGroupId, toGroupId });
        },
        [users],
    );

    const handleBulkAssignConfirm = useCallback(() => {
        if (!bulkAssignRequest) return;
        moveUsersWithinLimit(bulkAssignRequest.userIds, bulkAssignRequest.toGroupId);
        setBulkAssignRequest(null);
    }, [bulkAssignRequest, moveUsersWithinLimit]);

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const storedPreference = window.localStorage.getItem(ASSIGNMENT_VIEW_PREFERENCE_KEY);
        if (storedPreference !== null) {
            setIsSplitByPositionView(storedPreference === 'true');
        }
        setHasLoadedViewPreference(true);
    }, []);

    useEffect(() => {
        if (typeof window === 'undefined' || !hasLoadedViewPreference) return;

        window.localStorage.setItem(ASSIGNMENT_VIEW_PREFERENCE_KEY, String(isSplitByPositionView));
    }, [hasLoadedViewPreference, isSplitByPositionView]);

    const shiftTitle = useMemo(() => {
        if (!shift) return '';
        const { startTime, endTime } = DateHelper.formatWorkHour(
            shift.workplace_shift.start_time,
            shift.workplace_shift.end_time,
        );
        return `${shift.workplace?.name || ''} | ${startTime} ~ ${endTime}`;
    }, [shift]);

    const shiftStatus = useMemo(() => {
        if (!shift) return null;
        return ShiftHelper.getStatus(shift);
    }, [shift]);

    const orderedPositions = useMemo(() => {
        return [...positions].sort((a, b) => {
            if (a.sort_order === null && b.sort_order === null) {
                return a.id - b.id;
            }

            if (a.sort_order === null) {
                return 1;
            }

            if (b.sort_order === null) {
                return -1;
            }

            if (a.sort_order !== b.sort_order) {
                return a.sort_order - b.sort_order;
            }

            return a.id - b.id;
        });
    }, [positions]);

    const positionFixedTotals = useMemo(() => {
        const totals: Record<string, number> = {};
        for (const positionTotal of data?.data?.position_totals || []) {
            totals[positionTotal.workplace_position_id.toString()] = positionTotal.fixed_total;
        }
        return totals;
    }, [data]);

    const approvedUserPositionMeta = useMemo(() => {
        return orderedPositions.reduce<Record<string, { groupId: string; name: string; sortOrder: number | null }>>(
            (acc, position) => {
                const positionId = position.id.toString();
                for (const userId of groupUsers[positionId] || []) {
                    acc[userId] = {
                        groupId: positionId,
                        name: position.name,
                        sortOrder: position.sort_order,
                    };
                }
                return acc;
            },
            {},
        );
    }, [orderedPositions, groupUsers]);

    const combinedApprovedUserIds = useMemo(() => {
        return orderedPositions.flatMap((position) => groupUsers[position.id.toString()] || []);
    }, [orderedPositions, groupUsers]);

    const combinedApprovedErrors = useMemo(() => {
        return Object.values(positionErrors).reduce<Record<string, string[]>>((acc, errorMap) => {
            for (const [userId, messages] of Object.entries(errorMap || {})) {
                if (!acc[userId]) {
                    acc[userId] = [];
                }
                acc[userId].push(...messages);
            }
            return acc;
        }, {});
    }, [positionErrors]);

    const leaderBonusByUserId = useMemo(() => {
        return (shift?.workplace_shift.leaders || []).reduce<Record<string, number>>((acc, leader) => {
            acc[leader.user_id.toString()] = leader.bonus_per_hour;
            return acc;
        }, {});
    }, [shift]);

    const breadcrumbs = (
        <Breadcrumbs>
            <Anchor component={Link} href={RouteConfig.HOME.path}>
                {t('Home')}
            </Anchor>
            <Anchor component={Link} href={RouteConfig.MANAGE_SHIFT_LIST.path}>
                {t('Shift List')}
            </Anchor>
            <Text>{t('Shift Detail')}</Text>
        </Breadcrumbs>
    );

    if (!id) return null;

    if (isLoading || !data?.data || !shift) {
        return (
            <>
                {breadcrumbs}
                <Flex w="100%" h="300px" justify="center" align="center">
                    <Loader />
                </Flex>
            </>
        );
    }

    return (
        <>
            {breadcrumbs}
            <Title order={4} lh="48px" my="md">
                {shiftTitle}
            </Title>

            <Box pb={'md'}>
                {shift && (
                    <Box mb="lg">
                        <ShiftInfoCard
                            shift={shift}
                            hotBonusAmounts={hotBonusAmounts}
                            canEdit={!isReadOnly}
                            onUpdateConfig={() => setIsConfigModalOpen(true)}
                            onExport={handleExport}
                        />
                    </Box>
                )}

                <Box ref={errorAlertsRef}>
                    <ErrorAlerts
                        formNotMemberError={form.errors['not_member_ids']}
                        formDisabledError={form.errors['disabled_ids']}
                        formTotalErrors={[
                            { key: 'fixed_total', error: form.errors['fixed_total'] },
                            { key: 'temp_total', error: form.errors['temp_total'] },
                        ].filter((item) => Boolean(item.error))}
                        csvImportErrorData={csvImportErrorData}
                    />
                </Box>

                <Flex justify="flex-start" align="center" gap="md" mb="md" mt="md" wrap="wrap">
                    {perms.SHIFT_IMPORT && (
                        <Button
                            size="sm"
                            color="yellow"
                            leftSection={<LuUpload size={14} />}
                            onClick={() => setIsShowImportModal(true)}
                            disabled={isReadOnly}
                        >
                            {t('Import CSV')}
                        </Button>
                    )}
                    {perms.SHIFT_UPDATE_OVERTIME && (
                        <Button
                            size="sm"
                            variant="light"
                            leftSection={<LuClock size={14} />}
                            onClick={() => setIsOvertimeModalOpen(true)}
                            disabled={isShiftEnded}
                        >
                            {t('Manage overtime')}
                        </Button>
                    )}
                    {perms.SHIFT_DELETE && (
                        <Button
                            size="sm"
                            color="red"
                            variant="light"
                            leftSection={<LuTrash2 size={14} />}
                            onClick={() => setIsDeleteModalOpen(true)}
                        >
                            {t('Delete shift')}
                        </Button>
                    )}
                    <Switch
                        size="sm"
                        label={
                            isSplitByPositionView
                                ? t('View approved list grouped by position')
                                : t('View approved list in one table')
                        }
                        checked={isSplitByPositionView}
                        onChange={(event) => setIsSplitByPositionView(event.currentTarget.checked)}
                    />
                </Flex>

                {/* Pending Table */}
                <AssignmentTable
                    shiftId={id}
                    shiftDate={shift.date}
                    shiftStatus={shiftStatus || ''}
                    groupId="pending"
                    attendanceScope="pending"
                    title={t('Register list ({{count}})', { count: groupUsers.pending?.length || 0 })}
                    userIds={groupUsers.pending || []}
                    users={users}
                    userIsFixed={userIsFixed}
                    userFixedSchedulePosition={userFixedSchedulePosition}
                    userHasAbsentNotice={userHasAbsentNotice}
                    userStatus={userStatus}
                    shiftSalary={shift.salary_per_hour}
                    leaderBonusByUserId={leaderBonusByUserId}
                    userPhoneNumbers={userPhoneNumbers}
                    userShiftMeta={userShiftMeta}
                    onUserShiftMetaChange={updateUserShiftMeta}
                    positions={orderedPositions}
                    onMove={moveUsersWithinLimit}
                    onRemove={removeUsers}
                    onBulkAssign={handleBulkAssign}
                    isReadOnly={isReadOnly}
                    resetKey={selectionResetKey}
                    weeklyWorkLoads={weeklyWorkLoads}
                    initialAttendanceFilter={getInitialAttendanceFilter('pending')}
                />

                {/* Position Tables */}
                {isSplitByPositionView ? (
                    orderedPositions.map((position) => {
                        const posId = position.id.toString();
                        const posUserIds = groupUsers[posId] || [];
                        const positionTarget = positionFixedTotals[posId];
                        const positionTitle =
                            positionTarget === undefined
                                ? `${position.name} (${posUserIds.length})`
                                : `${position.name} (${posUserIds.length}/${positionTarget})`;
                        return (
                            <AssignmentTable
                                key={posId}
                                shiftId={id}
                                shiftDate={shift.date}
                                shiftStatus={shiftStatus || ''}
                                groupId={posId}
                                // Stable 'approved' scope, not posId: these per-position tables
                                // are just a display split of the same approved roster shown by
                                // the combined table below, so a shared link must resolve the
                                // same way regardless of which view mode is currently active.
                                attendanceScope="approved"
                                title={positionTitle}
                                userIds={posUserIds}
                                users={users}
                                userIsFixed={userIsFixed}
                                userFixedSchedulePosition={userFixedSchedulePosition}
                                userHasAbsentNotice={userHasAbsentNotice}
                                userStatus={userStatus}
                                shiftSalary={shift.salary_per_hour}
                                leaderBonusByUserId={leaderBonusByUserId}
                                userPhoneNumbers={userPhoneNumbers}
                                userShiftMeta={userShiftMeta}
                                onUserShiftMetaChange={updateUserShiftMeta}
                                positions={orderedPositions}
                                onMove={moveUsersWithinLimit}
                                onRemove={removeUsers}
                                onBulkAssign={handleBulkAssign}
                                onAddUsers={() => setAddToPositionId(posId)}
                                errors={positionErrors[posId]}
                                isReadOnly={isReadOnly}
                                resetKey={selectionResetKey}
                                weeklyWorkLoads={weeklyWorkLoads}
                                initialAttendanceFilter={getInitialAttendanceFilter('approved')}
                            />
                        );
                    })
                ) : (
                    <AssignmentTable
                        shiftId={id}
                        shiftDate={shift.date}
                        shiftStatus={shiftStatus || ''}
                        groupId="approved"
                        attendanceScope="approved"
                        title={`${t('Approved')} (${combinedApprovedUserIds.length})`}
                        userIds={combinedApprovedUserIds}
                        users={users}
                        userIsFixed={userIsFixed}
                        userFixedSchedulePosition={userFixedSchedulePosition}
                        userHasAbsentNotice={userHasAbsentNotice}
                        userStatus={userStatus}
                        shiftSalary={shift.salary_per_hour}
                        leaderBonusByUserId={leaderBonusByUserId}
                        userPhoneNumbers={userPhoneNumbers}
                        userShiftMeta={userShiftMeta}
                        onUserShiftMetaChange={updateUserShiftMeta}
                        positions={orderedPositions}
                        onMove={moveUsersWithinLimit}
                        onRemove={removeUsers}
                        onBulkAssign={handleBulkAssign}
                        errors={combinedApprovedErrors}
                        isReadOnly={isReadOnly}
                        resetKey={selectionResetKey}
                        weeklyWorkLoads={weeklyWorkLoads}
                        initialAttendanceFilter={getInitialAttendanceFilter('approved')}
                        userPositionMeta={approvedUserPositionMeta}
                        showPositionColumn
                    />
                )}

                {/* Rejected Table */}
                <AssignmentTable
                    shiftId={id}
                    shiftDate={shift.date}
                    shiftStatus={shiftStatus || ''}
                    groupId="rejected"
                    attendanceScope="rejected"
                    title={t('Rejected list ({{count}})', { count: groupUsers.rejected?.length || 0 })}
                    userIds={groupUsers.rejected || []}
                    users={users}
                    userIsFixed={userIsFixed}
                    userFixedSchedulePosition={userFixedSchedulePosition}
                    userHasAbsentNotice={userHasAbsentNotice}
                    userStatus={userStatus}
                    shiftSalary={shift.salary_per_hour}
                    leaderBonusByUserId={leaderBonusByUserId}
                    userPhoneNumbers={userPhoneNumbers}
                    userShiftMeta={userShiftMeta}
                    onUserShiftMetaChange={updateUserShiftMeta}
                    positions={orderedPositions}
                    onMove={moveUsersWithinLimit}
                    onRemove={removeUsers}
                    onBulkAssign={handleBulkAssign}
                    isReadOnly={isReadOnly}
                    resetKey={selectionResetKey}
                    weeklyWorkLoads={weeklyWorkLoads}
                    initialAttendanceFilter={getInitialAttendanceFilter('rejected')}
                />
            </Box>

            <div className={classes.actionBar}>
                <ActionBar
                    isReadOnly={isReadOnly}
                    hasChanges={hasChanges}
                    isSaving={updateMutation.isPending}
                    onCancel={handleCancel}
                    onSave={() => form.onSubmit(handleSave)()}
                />
            </div>

            {/* Modals */}
            <ModalImportCSV
                isShow={isShowImportModal}
                onClose={() => setIsShowImportModal(false)}
                encoding={encoding}
                setEncoding={setEncoding}
                isButtonLoading={importCsvMutation.isPending}
                onShiftCsvUpload={handleCsvUpload}
                fileInputRef={fileInputRef}
            />
            <AddUserModal
                isOpen={addToPositionId !== null}
                onClose={() => setAddToPositionId(null)}
                onAddUser={handleAddUsers}
                shiftId={id}
                shiftHours={shift?.work_hours}
                workplaceId={shift?.workplace_id.toString() || ''}
                workplaceShiftId={shift?.workplace_shift_id.toString() || ''}
            />
            <BulkAssignModal
                request={bulkAssignRequest}
                positions={orderedPositions}
                shiftHours={shift?.work_hours}
                weeklyWorkLoads={weeklyWorkLoads}
                onConfirm={handleBulkAssignConfirm}
                onCancel={() => setBulkAssignRequest(null)}
            />
            {shift && (
                <ConfigModal isOpen={isConfigModalOpen} onClose={() => setIsConfigModalOpen(false)} shift={shift} />
            )}
            <OvertimeModal
                isOpen={isOvertimeModalOpen}
                onClose={() => setIsOvertimeModalOpen(false)}
                shiftId={id}
                users={users}
            />
            <DeleteShiftModal
                isOpen={isDeleteModalOpen}
                onClose={() => setIsDeleteModalOpen(false)}
                shiftId={id}
                onDeleted={async () => {
                    setIsDeleteModalOpen(false);
                    await router.push(RouteConfig.MANAGE_SHIFT_LIST.path);
                    // Forget the deleted shift only once this page is gone: removing the entries
                    // while their queries are still mounted would make them refetch (and 404).
                    queryClient.removeQueries({ queryKey: ['manage/shifts', id] });
                }}
            />
        </>
    );
};

export default IndexPage;
