import React from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuCalendar,
    LuCloudUpload,
    LuKeyRound,
    LuLock,
    LuLockOpen,
    LuPencilLine,
    LuRedo,
    LuSave,
    LuX,
} from 'react-icons/lu';
import Link from 'next/link';
import { Button, Flex, Modal, Paper } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import RouteHelper from '@/helpers/route.helper';
import DConfirmModal from '@/components/DConfirmModal';
import ResetPasswordForm from '@/pages/admin/user/partials/reset_password_form';
import UserLockForm from '@/pages/admin/user/partials/user_lock_form';
import UserLockWarningModal from '@/pages/admin/user/partials/user_lock_warning_modal';
import UserUnlockForm from '@/pages/admin/user/partials/user_unlock_form';
import { usePermissions } from '@/hooks/usePermission';
import {
    UserResponseData,
    useGetUserUpcomingShiftsQuery,
    useRestoreUserMutation,
    useSyncUserFromCrossStaffMutation,
    useSyncUserToCrossStaffMutation,
} from '@/pages/admin/user/api';

const UserAction = ({
    canManageAccount,
    canSyncCrossStaff,
    id,
    user,
    isLoading,
    isReadOnly,
    onEdit,
    onCancel,
    formId,
    isSaving,
}: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'USER_DISABLE',
        'USER_ENABLE',
        'USER_RESTORE',
        'USER_RESET_PASSWORD',
        'USER_SYNC',
        'USER_EDIT',
    ]);
    const { mutateAsync: restoreUser } = useRestoreUserMutation(id);
    const { mutateAsync: syncToCrossStaff, isPending: isSyncingToCS } = useSyncUserToCrossStaffMutation(id);
    const { mutateAsync: syncFromCrossStaff, isPending: isSyncingFromCS } = useSyncUserFromCrossStaffMutation(id);
    const [isShowLock, { open: openLock, close: closeLock }] = useDisclosure(false);
    const [isShowLockWarning, { open: openLockWarning, close: closeLockWarning }] = useDisclosure(false);
    const [isShowUnlock, { open: openUnlock, close: closeUnlock }] = useDisclosure(false);
    const [isShowResetPassword, { open: openResetPassword, close: closeResetPassword }] = useDisclosure(false);
    const [isShowRestore, { open: openRestore, close: closeRestore }] = useDisclosure(false);
    const {
        data: upcomingShifts,
        isFetching: isFetchingUpcomingShifts,
        refetch: refetchUpcomingShifts,
    } = useGetUserUpcomingShiftsQuery(id);
    const shiftsToReject = upcomingShifts?.data ?? [];

    const onLockClick = async () => {
        const { data, isError } = await refetchUpcomingShifts();
        if (isError) {
            notifications.show({
                color: 'red',
                message: t('error|Could not load the upcoming shifts of this account'),
            });
            return;
        }
        if ((data?.data?.length ?? 0) > 0) {
            openLockWarning();
            return;
        }
        openLock();
    };

    if (isLoading || !user) {
        return (
            <Paper
                radius="md"
                h={60}
                mb={24}
                style={{ border: '1px solid var(--mantine-color-gray-3)', flexShrink: 0 }}
            />
        );
    }

    const isDeletedUser = /^del\d{10}_/.test(user.email);

    return (
        <>
            <Paper
                radius="md"
                p="md"
                mb="md"
                style={{ border: '1px solid var(--mantine-color-gray-3)', flexShrink: 0 }}
            >
                <Flex justify="space-between" align="center" h="100%">
                    {/* Left actions */}
                    <Flex gap={12} align="center">
                        <Button
                            variant="outline"
                            size="sm"
                            leftSection={<LuCalendar size={14} />}
                            disabled={!canManageAccount}
                            component={Link}
                            href={RouteHelper.getPath('MANAGE_USER_DETAIL_SCHEDULE', { id })}
                        >
                            {t('View schedule')}
                        </Button>
                        {!user.is_disabled && (
                            <Button
                                size="sm"
                                color="red"
                                leftSection={<LuLock size={14} />}
                                onClick={onLockClick}
                                loading={isFetchingUpcomingShifts}
                                disabled={!perms.USER_DISABLE}
                            >
                                {t('Lock account')}
                            </Button>
                        )}
                        {user.is_disabled && !isDeletedUser && (
                            <Button
                                size="sm"
                                leftSection={<LuLockOpen size={14} />}
                                onClick={openUnlock}
                                disabled={!perms.USER_ENABLE}
                            >
                                {t('Unlock account')}
                            </Button>
                        )}
                        {!user.is_disabled && perms.USER_RESET_PASSWORD && (
                            <Button
                                size="sm"
                                leftSection={<LuKeyRound size={14} />}
                                onClick={openResetPassword}
                                disabled={!canManageAccount}
                            >
                                {t('Reset password')}
                            </Button>
                        )}
                        {isDeletedUser && (
                            <Button
                                size="sm"
                                color="orange"
                                leftSection={<LuRedo size={14} />}
                                onClick={openRestore}
                                disabled={!perms.USER_RESTORE}
                            >
                                {t('Restore account')}
                            </Button>
                        )}
                        {!user.is_disabled && (
                            <Button
                                size="sm"
                                leftSection={<LuCloudUpload size={14} />}
                                onClick={async () => {
                                    try {
                                        await syncToCrossStaff();
                                        notifications.show({
                                            color: 'teal',
                                            message: t('Successfully synced to CrossStaff'),
                                        });
                                    } catch (e) {
                                        let msg = t('error|Could not sync to CrossStaff');
                                        if (e instanceof ApiError) {
                                            msg += `: ${e.data as unknown as string}`;
                                        }
                                        notifications.show({ color: 'red', message: msg });
                                    }
                                }}
                                disabled={!canSyncCrossStaff || !perms.USER_SYNC}
                                loading={isSyncingToCS}
                            >
                                {t('Sync to CrossStaff')}
                            </Button>
                        )}
                        {!user.is_disabled && (
                            <Button
                                size="sm"
                                leftSection={<LuSave size={14} />}
                                onClick={async () => {
                                    try {
                                        await syncFromCrossStaff();
                                        notifications.show({
                                            color: 'teal',
                                            message: t('Successfully synced from CrossStaff'),
                                        });
                                    } catch (e) {
                                        notifications.show({
                                            color: 'red',
                                            message: t('error|Could not sync from CrossStaff'),
                                        });
                                    }
                                }}
                                disabled={!canSyncCrossStaff || !perms.USER_SYNC}
                                loading={isSyncingFromCS}
                            >
                                {t('Sync from CrossStaff')}
                            </Button>
                        )}
                    </Flex>

                    {/* Right actions - Edit controls */}
                    <Flex gap={8} align="center">
                        {isReadOnly ? (
                            <Button
                                size="sm"
                                leftSection={<LuPencilLine size={14} />}
                                disabled={!perms.USER_EDIT}
                                onClick={onEdit}
                            >
                                {t('Edit')}
                            </Button>
                        ) : (
                            <>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    color="gray"
                                    leftSection={<LuX size={14} />}
                                    onClick={onCancel}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button
                                    size="sm"
                                    type="submit"
                                    form={formId}
                                    color="teal"
                                    leftSection={<LuSave size={14} />}
                                    loading={isSaving}
                                >
                                    {t('Save changes')}
                                </Button>
                            </>
                        )}
                    </Flex>
                </Flex>
            </Paper>

            <DConfirmModal
                opened={isShowRestore}
                title={t('Restore account')}
                message={t('Are you sure you want to restore this account?')}
                onConfirm={async () => {
                    try {
                        await restoreUser();
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully restore account'),
                        });
                    } catch (e) {
                        let message = t('error|Could not restore account');
                        if (e instanceof ApiError) {
                            if (e.message === ErrorCodeEnum.USER_EMAIL_ALREADY_EXISTS.value) {
                                message = t('error|Email already exists, cannot restore account');
                            }
                        }
                        notifications.show({
                            color: 'red',
                            message: message,
                        });
                    } finally {
                        closeRestore();
                    }
                }}
                onCancel={closeRestore}
            />
            <UserLockWarningModal
                opened={isShowLockWarning}
                shifts={shiftsToReject}
                onConfirm={() => {
                    closeLockWarning();
                    openLock();
                }}
                onCancel={closeLockWarning}
            />
            <Modal opened={isShowLock} onClose={closeLock} title={t('Lock account')}>
                <UserLockForm id={id} closeLock={closeLock} />
            </Modal>
            <Modal opened={isShowUnlock} onClose={closeUnlock} title={t('Unlock account')}>
                <UserUnlockForm id={id} closeUnlock={closeUnlock} />
            </Modal>
            <Modal opened={isShowResetPassword} onClose={closeResetPassword} title={t('Reset password')}>
                <ResetPasswordForm id={id} close={closeResetPassword} />
            </Modal>
        </>
    );
};

type Props = {
    canManageAccount: boolean;
    canSyncCrossStaff: boolean;
    id: string;
    user: UserResponseData | null | undefined;
    isLoading: boolean;
    isReadOnly: boolean;
    onEdit: () => void;
    onCancel: () => void;
    formId: string;
    isSaving: boolean;
};

export default UserAction;
