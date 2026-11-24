import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRouter } from 'next/router';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ShiftCsvError } from '@/types/models/shift';
import { ShiftUserWithRelations } from '@/types/models/shift_user';
import { UserWithAbsenceCount } from '@/types/models/user';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { AbsentNoticeStatusEnum } from '@/enums/absent_notice_status.enum';
import { EncodingEnum } from '@/enums/encoding.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import ShiftHelper from '@/helpers/shift.helper';
import { setFormErrors } from '@/pages/manage/shift/partials/update/helper';
import { usePermissions } from '@/hooks/usePermission';
import {
    FixedScheduleUser,
    exportTimesheet,
    useGetShiftDetailQuery,
    useImportCsvShiftMutation,
    useUpdateShiftMutation,
} from '@/pages/manage/shift/api';

export type ShiftFormValue = number | string;
export type ShiftForm = Record<string, ShiftFormValue>;
export type UserShiftTableMeta = {
    shiftUserId: number;
    salaryPerHour: number | null;
    isOvertimeAllowed: boolean;
    confirmedAt: string | null;
    workplacePositionSalary: number | null;
    checkInStart: string | null;
    checkInEnd: string | null;
    isAbsent: boolean;
    isUrgentRegistration: boolean;
};

const categorizeAllUsers = (
    shiftUsers: ShiftUserWithRelations[],
    fixedScheduleUsers: FixedScheduleUser[],
    positions: WorkplacePosition[],
) => {
    const users: Record<string, UserWithAbsenceCount> = {};
    const userIsFixed: Record<string, boolean> = {};
    const userFixedSchedulePosition: Record<string, string | null> = {};
    const userGroups: Record<string, string> = {};
    const userHasAbsentNotice: Record<string, boolean> = {};
    const userStatus: Record<string, string> = {};
    const userPhoneNumbers: Record<string, string | null> = {};
    const userShiftMeta: Record<string, UserShiftTableMeta> = {};
    const groupUsers: Record<string, string[]> = { pending: [], rejected: [] };
    for (const position of positions) {
        groupUsers[position.id.toString()] = [];
    }

    for (const shiftUser of shiftUsers) {
        const userId = shiftUser.user.id.toString();
        users[userId] = shiftUser.user;
        userIsFixed[userId] = !!shiftUser.is_fixed;
        // Only an unhandled request still needs the admin's attention here: a processed one
        // already moved the employee to the rejected list, and a refused one means the admin
        // decided the employee works this shift after all.
        userHasAbsentNotice[userId] = shiftUser.absent_notice?.status === AbsentNoticeStatusEnum.PENDING.value;
        userStatus[userId] = shiftUser.status;
        userPhoneNumbers[userId] = shiftUser.user.info?.phone ?? null;
        userShiftMeta[userId] = {
            shiftUserId: shiftUser.id,
            salaryPerHour: shiftUser.salary_per_hour,
            isOvertimeAllowed: shiftUser.is_overtime_allowed,
            confirmedAt: shiftUser.confirmed_at,
            workplacePositionSalary: shiftUser.workplace_position?.salary_per_hour ?? null,
            checkInStart: shiftUser.check_in?.check_in_time ?? null,
            checkInEnd: shiftUser.check_in?.check_out_time ?? null,
            isAbsent: shiftUser.is_absent,
            isUrgentRegistration: !!shiftUser.is_urgent_registration,
        };

        if (shiftUser.status === ShiftUserStatusEnum.PENDING.value) {
            groupUsers.pending.push(userId);
            userGroups[userId] = 'pending';
        } else if (shiftUser.status === ShiftUserStatusEnum.REJECTED.value) {
            groupUsers.rejected.push(userId);
            userGroups[userId] = 'rejected';
        } else if (shiftUser.workplace_position_id) {
            const key = shiftUser.workplace_position_id.toString();
            if (!groupUsers[key]) {
                groupUsers[key] = [];
            }
            groupUsers[key].push(userId);
            userGroups[userId] = key;
        }
    }

    // Add fixed schedule users that are not yet in the shift
    for (const user of fixedScheduleUsers) {
        const userId = user.id.toString();
        userFixedSchedulePosition[userId] = user.fixed_schedule_position?.name ?? null;
        if (!users[userId]) {
            users[userId] = user;
            userIsFixed[userId] = true;
            groupUsers.pending.push(userId);
            userGroups[userId] = 'pending';
        }
        if (!(userId in userPhoneNumbers)) {
            userPhoneNumbers[userId] = user.info?.phone ?? null;
        }
    }

    return {
        users,
        userIsFixed,
        userFixedSchedulePosition,
        userGroups,
        groupUsers,
        userHasAbsentNotice,
        userStatus,
        userPhoneNumbers,
        userShiftMeta,
    };
};

export const useShiftDetail = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const perms = usePermissions([
        'SHIFT_LIST',
        'SHIFT_EDIT',
        'SHIFT_EXPORT',
        'SHIFT_IMPORT',
        'SHIFT_UPDATE_OVERTIME',
        'SHIFT_DELETE',
    ]);
    const { data, isLoading, refetch } = useGetShiftDetailQuery(id || '0', perms.SHIFT_LIST);
    const updateMutation = useUpdateShiftMutation(id || '0');
    const importCsvMutation = useImportCsvShiftMutation(id || '0');

    const [users, setUsers] = useState<Record<string, UserWithAbsenceCount>>({});
    const [userIsFixed, setUserIsFixed] = useState<Record<string, boolean>>({});
    const [userFixedSchedulePosition, setUserFixedSchedulePosition] = useState<Record<string, string | null>>({});
    const [userGroups, setUserGroups] = useState<Record<string, string>>({});
    const [groupUsers, setGroupUsers] = useState<Record<string, string[]>>({ pending: [], rejected: [] });
    const [initialUserGroups, setInitialUserGroups] = useState<Record<string, string>>({});
    const [positionErrors, setPositionErrors] = useState<Record<string, Record<string, string[]>>>({});
    const [csvImportErrorData, setCsvImportErrorData] = useState<ShiftCsvError[]>([]);
    const [addToPositionId, setAddToPositionId] = useState<string | null>(null);
    const [isShowImportModal, setIsShowImportModal] = useState(false);
    const [encoding, setEncoding] = useState<string>(EncodingEnum.SHIFT_JIS.value);
    const [selectionResetKey, setSelectionResetKey] = useState(0);
    const [userHasAbsentNotice, setUserHasAbsentNotice] = useState<Record<string, boolean>>({});
    const [userStatus, setUserStatus] = useState<Record<string, string>>({});
    const [userPhoneNumbers, setUserPhoneNumbers] = useState<Record<string, string | null>>({});
    const [userShiftMeta, setUserShiftMeta] = useState<Record<string, UserShiftTableMeta>>({});
    const fileInputRef = useRef<() => void>(() => {});

    const form = useForm<ShiftForm>({
        initialValues: {
            fixed_total: 0,
            temp_total: 0,
            max_registered_temp: '',
            break_minutes: '',
        },
        validate: validatorResolver<ShiftFormValue>({
            fixed_total: Validator.number().required().gte(0),
            temp_total: Validator.number().required().gte(0),
        }),
    });

    // Initialize state from API data
    useEffect(() => {
        if (!data?.data) {
            form.setValues({ fixed_total: 0, temp_total: 0 });
            form.resetDirty();
            return;
        }
        const shift = data.data.shift;
        form.setValues({
            fixed_total: shift.fixed_total,
            temp_total: shift.temp_total,
            max_registered_temp: shift.max_registered_temp ?? '',
            break_minutes: shift.break_minutes ?? '',
        });
        form.resetDirty();

        const result = categorizeAllUsers(data.data.shift_users, data.data.fixed_schedule_users, data.data.positions);
        setUsers(result.users);
        setUserIsFixed(result.userIsFixed);
        setUserFixedSchedulePosition(result.userFixedSchedulePosition);
        setUserGroups(result.userGroups);
        setGroupUsers(result.groupUsers);
        setInitialUserGroups(result.userGroups);
        setPositionErrors({});
        setCsvImportErrorData([]);
        setUserHasAbsentNotice(result.userHasAbsentNotice);
        setUserStatus(result.userStatus);
        setUserPhoneNumbers(result.userPhoneNumbers);
        setUserShiftMeta(result.userShiftMeta);
    }, [data]);

    const isAssignmentDirty = useMemo(() => {
        const allKeys = new Set([...Object.keys(userGroups), ...Object.keys(initialUserGroups)]);
        for (const key of allKeys) {
            if (userGroups[key] !== initialUserGroups[key]) return true;
        }
        return false;
    }, [userGroups, initialUserGroups]);

    const hasChanges = form.isDirty() || isAssignmentDirty;

    const isReadOnly = useMemo(() => {
        if (!perms.SHIFT_EDIT) return true;
        if (!data?.data) return true;
        return ShiftHelper.isShiftEnded(data.data.shift);
    }, [data, perms.SHIFT_EDIT]);

    // Whether the shift has already ended. Unlike `isReadOnly`, this does NOT require
    // SHIFT_EDIT, so actions gated by their own permission (e.g. overtime) can disable
    // themselves only when the shift is over, not when the user lacks edit rights.
    const isShiftEnded = useMemo(() => {
        if (!data?.data) return true;
        return ShiftHelper.isShiftEnded(data.data.shift);
    }, [data]);

    const positions = data?.data?.positions || [];
    const shift = data?.data?.shift;
    const hotBonusAmounts = data?.data?.hot_bonus_amounts || [];

    const moveUsers = useCallback(
        (userIds: string[], targetGroup: string) => {
            setGroupUsers((prev) => {
                const next = { ...prev };
                for (const key in next) {
                    next[key] = [...next[key]];
                }
                if (!next[targetGroup]) {
                    next[targetGroup] = [];
                }

                const targetSet = new Set(next[targetGroup]);
                for (const userId of userIds) {
                    const source = userGroups[userId];
                    if (source && source !== targetGroup) {
                        next[source] = next[source].filter((id) => id !== userId);
                    }
                    if (!targetSet.has(userId)) {
                        targetSet.add(userId);
                        next[targetGroup].push(userId);
                    }
                }
                return next;
            });
            setUserGroups((prev) => {
                const next = { ...prev };
                for (const userId of userIds) {
                    next[userId] = targetGroup;
                }
                return next;
            });
            // Clear errors for moved users
            setPositionErrors((prev) => {
                const next = { ...prev };
                for (const posId in next) {
                    const posErrors = { ...next[posId] };
                    for (const userId of userIds) {
                        delete posErrors[userId];
                    }
                    next[posId] = posErrors;
                }
                return next;
            });
        },
        [userGroups],
    );

    const handleAddUsers = useCallback(
        (addedUsers: UserWithAbsenceCount[], isFixed: boolean) => {
            if (!addToPositionId || addedUsers.length === 0) return;
            const newUsers = { ...users };
            const newUserIsFixed = { ...userIsFixed };
            const userIds: string[] = [];
            for (const user of addedUsers) {
                const userId = user.id.toString();
                newUsers[userId] = user;
                newUserIsFixed[userId] = isFixed;
                userIds.push(userId);
            }
            setUsers(newUsers);
            setUserIsFixed(newUserIsFixed);
            moveUsers(userIds, addToPositionId);
        },
        [addToPositionId, users, userIsFixed, moveUsers],
    );

    const removeUsers = useCallback((userIds: string[]) => {
        if (userIds.length === 0) return;
        const idSet = new Set(userIds);
        setUsers((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setUserIsFixed((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setUserGroups((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setGroupUsers((prev) => {
            const next: Record<string, string[]> = {};
            for (const key in prev) {
                next[key] = prev[key].filter((id) => !idSet.has(id));
            }
            return next;
        });
        setUserHasAbsentNotice((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setUserStatus((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setUserPhoneNumbers((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setUserShiftMeta((prev) => {
            const next = { ...prev };
            for (const id of userIds) delete next[id];
            return next;
        });
        setPositionErrors((prev) => {
            const next = { ...prev };
            for (const posId in next) {
                const posErrors = { ...next[posId] };
                for (const id of userIds) delete posErrors[id];
                next[posId] = posErrors;
            }
            return next;
        });
    }, []);

    const updateUserShiftMeta = useCallback((userId: string, values: Partial<UserShiftTableMeta>) => {
        setUserShiftMeta((prev) => {
            const current = prev[userId];
            if (!current) {
                return prev;
            }

            return {
                ...prev,
                [userId]: {
                    ...current,
                    ...values,
                },
            };
        });
    }, []);

    const handleSave = async (values: ShiftForm) => {
        setCsvImportErrorData([]);
        if (isReadOnly || !data?.data) return;

        const requestData: Record<string, any> = {
            fixed_total: values.fixed_total,
            temp_total: values.temp_total,
            max_registered_temp: values.max_registered_temp,
            break_minutes: values.break_minutes,
        };

        // Split groupUsers by is_fixed
        const fixed: Record<string, string[]> = { pending: [], rejected: [] };
        const temp: Record<string, string[]> = { pending: [], rejected: [] };

        for (const groupId in groupUsers) {
            for (const userId of groupUsers[groupId]) {
                const isFixed = userIsFixed[userId];
                const target = isFixed ? fixed : temp;
                if (!target[groupId]) {
                    target[groupId] = [];
                }
                target[groupId].push(userId);
            }
        }

        requestData.fixed = {
            pending_ids: fixed.pending || [],
            rejected_ids: fixed.rejected || [],
            approved_ids: {} as Record<string, string[]>,
        };
        requestData.temp = {
            pending_ids: temp.pending || [],
            rejected_ids: temp.rejected || [],
            approved_ids: {} as Record<string, string[]>,
        };

        for (const key in fixed) {
            if (key !== 'pending' && key !== 'rejected') {
                requestData.fixed.approved_ids[key] = fixed[key];
            }
        }
        for (const key in temp) {
            if (key !== 'pending' && key !== 'rejected') {
                requestData.temp.approved_ids[key] = temp[key];
            }
        }

        try {
            await updateMutation.mutateAsync(requestData);
            NotificationHelper.showSuccess(t('Successfully updated shift'));
            await refetch();
        } catch (e) {
            if (e instanceof ApiValidationError) {
                const newPositionErrors: Record<string, Record<string, string[]>> = {};
                for (const key of Object.keys(e.data)) {
                    if (key === 'date') {
                        NotificationHelper.showError(t('error|You are not allow to update shifts in the past'));
                        break;
                    }
                    const errorData = setFormErrors(key, e.data[key][0], users, form);
                    if (errorData) {
                        const posId = errorData.positionId;
                        if (!newPositionErrors[posId]) {
                            newPositionErrors[posId] = {};
                        }
                        for (const userId of errorData.ids) {
                            const cleanId = userId.includes(':') ? userId.split(':')[0] : userId;
                            if (!newPositionErrors[posId][cleanId]) {
                                newPositionErrors[posId][cleanId] = [];
                            }
                            newPositionErrors[posId][cleanId].push(errorData.perUserMessage);
                        }
                    }
                }
                setPositionErrors(newPositionErrors);
            } else {
                NotificationHelper.showError(t('error|Could not update shift'));
            }
        }
    };

    const handleCancel = async () => {
        setCsvImportErrorData([]);
        setPositionErrors({});
        setSelectionResetKey((k) => k + 1);
        const { data: freshData } = await refetch();
        if (freshData?.data) {
            const shift = freshData.data.shift;
            form.setValues({
                fixed_total: shift.fixed_total,
                temp_total: shift.temp_total,
                max_registered_temp: shift.max_registered_temp ?? '',
                break_minutes: shift.break_minutes ?? '',
            });
            form.resetDirty();
            const result = categorizeAllUsers(
                freshData.data.shift_users,
                freshData.data.fixed_schedule_users,
                freshData.data.positions,
            );
            setUsers(result.users);
            setUserIsFixed(result.userIsFixed);
            setUserFixedSchedulePosition(result.userFixedSchedulePosition);
            setUserGroups(result.userGroups);
            setGroupUsers(result.groupUsers);
            setInitialUserGroups(result.userGroups);
            setUserHasAbsentNotice(result.userHasAbsentNotice);
            setUserStatus(result.userStatus);
            setUserPhoneNumbers(result.userPhoneNumbers);
            setUserShiftMeta(result.userShiftMeta);
        }
    };

    const handleCsvUpload = async (file: File | null, isFixed: boolean) => {
        setCsvImportErrorData([]);
        if (!file) return;

        try {
            const formData = new FormData();
            formData.append('_method', 'POST');
            formData.append('file', file);
            formData.append('encoding', encoding);
            const response = await importCsvMutation.mutateAsync(formData);
            if (response.data) {
                const { error_data, valid_data, users_in_workplace } = response.data;

                if (error_data && error_data.length > 0) {
                    setCsvImportErrorData(error_data as ShiftCsvError[]);
                }

                // Move all currently assigned users to pending first
                const usersToMoveToPending: string[] = [];
                for (const gId in groupUsers) {
                    if (gId !== 'pending' && gId !== 'rejected') {
                        usersToMoveToPending.push(...groupUsers[gId]);
                    }
                }
                if (usersToMoveToPending.length > 0) {
                    moveUsers(usersToMoveToPending, 'pending');
                }

                // Add imported users to their positions
                const newUsers = { ...users };
                const newUserIsFixed = { ...userIsFixed };
                for (const positionId in valid_data) {
                    const posUserIds: string[] = [];
                    for (const userId of valid_data[positionId]) {
                        const userIdStr = userId.toString();
                        const user = users_in_workplace[userId];
                        if (user) {
                            newUsers[userIdStr] = user;
                            newUserIsFixed[userIdStr] = isFixed;
                            posUserIds.push(userIdStr);
                        }
                    }
                    if (posUserIds.length > 0) {
                        moveUsers(posUserIds, positionId);
                    }
                }
                setUsers(newUsers);
                setUserIsFixed(newUserIsFixed);

                NotificationHelper.showSuccess(t('Successfully imported shift information'));
            }
        } catch (e) {
            if (e instanceof ApiError) {
                let message = t('error|Could not import shift information');
                if (e.message === ErrorCodeEnum.SHIFT_CSV_EMPTY.value) {
                    message = t('error|CSV file is empty');
                } else if (e.message === ErrorCodeEnum.SHIFT_CSV_INVALID_HEADER.value) {
                    message = t('error|CSV file has invalid header');
                } else if (e.message === ErrorCodeEnum.SHIFT_CSV_INVALID_ROW.value) {
                    message = t('error|CSV file has invalid row');
                }
                NotificationHelper.showError(message);
            }
        } finally {
            fileInputRef.current?.();
            setIsShowImportModal(false);
        }
    };

    const handleExport = async () => {
        try {
            await exportTimesheet(id, shift?.date || '');
        } catch {
            NotificationHelper.showError(t('error|Could not export shift information'));
        }
    };

    return {
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
    };
};
