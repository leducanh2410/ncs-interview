import React from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Badge, Button, Flex, Group, Loader, Modal, Stack, Text } from '@mantine/core';
import { TFunction } from 'i18next';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import getShiftActionErrorMessage from '@/pages/manage/shift/partials/shift_action_error';
import {
    ShiftDeleteBlockReason,
    ShiftDeleteImpact,
    ShiftDeleteImpactUser,
    useDeleteShiftMutation,
    useGetShiftDeleteImpactQuery,
} from '@/pages/manage/shift/api';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    shiftId: string;
    /** Called after the API confirmed the delete; the page owns navigation. */
    onDeleted: () => void;
};

const getBlockReasonMessage = (t: TFunction, reason: ShiftDeleteBlockReason | null): string => {
    if (reason === 'ended_with_approved') {
        return t('This shift has already ended with approved staff, so it cannot be deleted.');
    }
    return t('This shift already has attendance (check-in) records, so it cannot be deleted.');
};

const getDeleteErrorMessage = (t: TFunction, error: unknown): string => {
    if (error instanceof ApiError && error.message === ErrorCodeEnum.SHIFT_CANNOT_DELETE_HAS_ATTENDANCE.value) {
        return t('error|Shift cannot be deleted because it already has attendance data');
    }
    return getShiftActionErrorMessage(t, error, t('error|Could not delete shift'));
};

const getStatusLabel = (t: TFunction, status: string): string => {
    switch (status) {
        case ShiftUserStatusEnum.APPROVED.value:
            return t('Approved');
        case ShiftUserStatusEnum.REJECTED.value:
            return t('Rejected');
        default:
            return t('Pending');
    }
};

const getStatusColor = (status: string): string => {
    switch (status) {
        case ShiftUserStatusEnum.APPROVED.value:
            return ShiftUserStatusEnum.APPROVED.color;
        case ShiftUserStatusEnum.REJECTED.value:
            return ShiftUserStatusEnum.REJECTED.color;
        default:
            return ShiftUserStatusEnum.PENDING.color;
    }
};

const AffectedUserRow = ({ user, t }: { user: ShiftDeleteImpactUser; t: TFunction }) => (
    <Group gap="xs" wrap="nowrap">
        <Text size="sm" style={{ flex: 1 }} truncate>
            {user.name || `#${user.id}`}
        </Text>
        <Badge size="xs" variant="light" color={getStatusColor(user.status)}>
            {getStatusLabel(t, user.status)}
        </Badge>
        <Badge size="xs" variant="outline" color="gray">
            {user.is_fixed ? t('Fixed') : t('Temporary')}
        </Badge>
    </Group>
);

const ImpactSummary = ({ impact, t }: { impact: ShiftDeleteImpact; t: TFunction }) => {
    const hiddenUsers = impact.shift_users.total - impact.shift_users.users.length;

    return (
        <Stack gap="xs">
            <Text size="sm">
                {t('Registrations: {{total}} (pending {{pending}}, approved {{approved}}, rejected {{rejected}})', {
                    total: impact.shift_users.total,
                    pending: impact.shift_users.by_status.pending,
                    approved: impact.shift_users.by_status.approved,
                    rejected: impact.shift_users.by_status.rejected,
                })}
            </Text>
            <Text size="sm">
                {t('Fixed staff: {{fixed}}, temporary staff: {{temp}}', {
                    fixed: impact.shift_users.fixed,
                    temp: impact.shift_users.temp,
                })}
            </Text>
            <Text size="sm">
                {t('Attendance records: {{total}} ({{checkedOut}} already checked out)', {
                    total: impact.check_ins.total,
                    checkedOut: impact.check_ins.checked_out,
                })}
            </Text>
            <Text size="sm">{t('Shift reports: {{count}}', { count: impact.shift_reports })}</Text>
            <Text size="sm">{t('Absent notices: {{count}}', { count: impact.shift_absent_notices })}</Text>
            <Text size="sm">
                {t('Shift planner drafts that will be discarded: {{count}}', { count: impact.shift_plan_drafts })}
            </Text>
            <Text size="sm">
                {t('Chat conversations that will be closed: {{count}}', { count: impact.chat_conversations })}
            </Text>
            <Text size="sm">
                {t('Sent recruitment notifications that will point to a deleted shift: {{count}}', {
                    count: impact.recruitment_notifications,
                })}
            </Text>

            {impact.shift_users.users.length > 0 && (
                <Stack gap={4} mt="xs">
                    <Text size="sm" fw={600}>
                        {t('Affected employees')}
                    </Text>
                    {impact.shift_users.users.map((user) => (
                        <AffectedUserRow key={user.id} user={user} t={t} />
                    ))}
                    {hiddenUsers > 0 && (
                        <Text size="xs" c="dimmed">
                            {t('and {{count}} more', { count: hiddenUsers })}
                        </Text>
                    )}
                </Stack>
            )}
        </Stack>
    );
};

const DeleteShiftModal = ({ isOpen, onClose, shiftId, onDeleted }: Props) => {
    const { t } = useTranslation();
    const { data, isLoading, isError, refetch } = useGetShiftDeleteImpactQuery(shiftId, isOpen);
    const deleteMutation = useDeleteShiftMutation(shiftId);
    const impact = data?.data;
    const canConfirm = !!impact && !impact.blocked && !isLoading && !isError;

    const handleConfirm = async () => {
        if (!canConfirm) return;
        try {
            await deleteMutation.mutateAsync();
            NotificationHelper.showSuccess(t('Successfully deleted shift'));
            onDeleted();
        } catch (error) {
            NotificationHelper.showError(getDeleteErrorMessage(t, error));
            // The guard may have flipped since the preview (e.g. a check-in landed); show why.
            await refetch();
        }
    };

    return (
        <Modal opened={isOpen} onClose={onClose} title={t('Delete shift')} size="lg">
            <Stack gap="md">
                {isLoading && (
                    <Flex justify="center" py="md">
                        <Loader size="sm" />
                    </Flex>
                )}

                {isError && <Alert color="red">{t('error|Could not load the data affected by this deletion')}</Alert>}

                {impact && impact.blocked && (
                    <Alert color="red" title={t('Cannot delete this shift')}>
                        {getBlockReasonMessage(t, impact.block_reason)}
                    </Alert>
                )}

                {impact && !impact.blocked && (
                    <Alert color="red" title={t('This action cannot be undone')}>
                        {t('The shift and all data listed below will be permanently deleted.')}
                    </Alert>
                )}

                {impact && <ImpactSummary impact={impact} t={t} />}

                <Flex justify="flex-end" gap={8}>
                    <Button variant="filled" color="gray" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        variant="filled"
                        color="red"
                        onClick={handleConfirm}
                        disabled={!canConfirm}
                        loading={deleteMutation.isPending}
                    >
                        {t('Delete')}
                    </Button>
                </Flex>
            </Stack>
        </Modal>
    );
};

export default DeleteShiftModal;
