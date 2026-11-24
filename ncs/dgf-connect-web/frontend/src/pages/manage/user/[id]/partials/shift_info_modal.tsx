import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import Link from 'next/link';
import { Alert, Badge, Button, Group, Modal, Stack, Switch, Text } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { ApiResponse } from '@/types/api';
import { CalendarCard } from '@/types/shift';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import { ApiError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import WeeklyHourLimitHelper from '@/helpers/weekly_hour_limit.helper';
import { ToggleAbsentResult, useToggleAbsentMutation } from '@/pages/manage/user/[id]/api';

type Props = {
    card: CalendarCard | null;
    onClose: () => void;
};

/**
 * Dropping a shift's hours is refused when the month's salary has already been committed to.
 * Those cases need an explanation the admin can act on, not the generic failure notice.
 */
const getSalaryBlockMessage = (error: unknown, t: (key: string) => string): string | null => {
    if (!(error instanceof ApiError)) {
        return null;
    }

    if (error.message === ErrorCodeEnum.SHIFT_USER_ABSENT_SALARY_PAID.value) {
        return t(
            'Salary for this month has already been paid in part, so this shift cannot be marked as absent. Ask accounting to settle the month first.',
        );
    }

    if (error.message === ErrorCodeEnum.SHIFT_USER_ABSENT_SALARY_REQUESTED.value) {
        return t(
            'This employee has a payment request in progress for this month, so this shift cannot be marked as absent. Settle or cancel that request first.',
        );
    }

    return null;
};

const getRecalculationNote = (
    result: ApiResponse<ToggleAbsentResult>,
    card: CalendarCard | null,
    t: (key: string) => string,
): string => {
    if (!card?.hasCheckIn) {
        return '';
    }

    return result.data?.is_salary_settled
        ? t('The work time report will update in a few seconds. The settled salary for this month stays unchanged.')
        : t('The work time report and salary will update in a few seconds.');
};

const ShiftInfoModal = ({ card, onClose }: Props) => {
    const { t } = useTranslation();
    const [confirmModalOpened, setConfirmModalOpened] = useState(false);
    const [isConfirmingAbsent, setIsConfirmingAbsent] = useState(false);

    const toggleAbsentMutation = useToggleAbsentMutation(card?.id || 0);

    const handleToggleAbsentClick = () => {
        setIsConfirmingAbsent(!card?.isAbsent);
        setConfirmModalOpened(true);
    };

    const handleConfirmAbsent = () => {
        toggleAbsentMutation.mutate(undefined, {
            onSuccess: (result) => {
                const status = isConfirmingAbsent ? t('Shift marked as absent') : t('Shift marked as present');
                notifications.show({
                    message: `${status} ${getRecalculationNote(result, card, t)}`.trim(),
                    color: 'green',
                });
                setConfirmModalOpened(false);
                onClose();
            },
            onError: (error) => {
                const salaryBlockMessage =
                    getSalaryBlockMessage(error, t) ?? WeeklyHourLimitHelper.getAbsentUndoMessage(error, t);
                notifications.show({
                    message: salaryBlockMessage ?? t('Failed to update shift status'),
                    color: 'red',
                    autoClose: salaryBlockMessage ? false : undefined,
                });
                setConfirmModalOpened(false);
            },
        });
    };

    if (!card) {
        return null;
    }

    const needsCheckInWarning = isConfirmingAbsent && card.hasCheckIn;

    const getStatusColor = (status: string): string => {
        for (const key of Object.keys(ShiftUserStatusEnum)) {
            if (status === ShiftUserStatusEnum[key as keyof typeof ShiftUserStatusEnum].value) {
                return ShiftUserStatusEnum[key as keyof typeof ShiftUserStatusEnum].color;
            }
        }
        return 'gray';
    };

    return (
        <>
            <Modal opened={!!card} onClose={onClose} title={t('Shift Information')} size="md">
                <Stack gap={0}>
                    <Group px="sm" py="xs" bg="gray.2">
                        <Text size="sm" w={150}>
                            {t('Workplace')}
                        </Text>
                        <Text size="sm" fw={500}>
                            {card.workplaceName}
                        </Text>
                    </Group>
                    <Group px="sm" py="xs">
                        <Text size="sm" w={150}>
                            {t('Position')}
                        </Text>
                        <Text size="sm">{card.position}</Text>
                    </Group>
                    <Group px="sm" py="xs" bg="gray.2">
                        <Text size="sm" w={150}>
                            {t('Time')}
                        </Text>
                        <Text size="sm">
                            {card.startTime} ~ {card.endTime}
                        </Text>
                    </Group>
                    <Group px="sm" py="xs">
                        <Text size="sm" w={150}>
                            {t('Status')}
                        </Text>
                        <Badge color={getStatusColor(card.status)}>
                            {EnumHelper.getText(ShiftUserStatusEnum, card.status)}
                        </Badge>
                    </Group>
                    <Group px="sm" py="xs" bg="gray.2">
                        <Text size="sm" w={150}>
                            {t('Check-in status')}
                        </Text>
                        <Text size="sm">{card.hasCheckIn ? t('Checked in') : t('Not checked in')}</Text>
                    </Group>
                    {card.hasCheckIn ? (
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Work hours')}
                            </Text>
                            <Text
                                size="sm"
                                c={card.isAbsent ? 'dimmed' : undefined}
                                td={card.isAbsent ? 'line-through' : undefined}
                            >
                                {card.workHours} {t('hours')}
                            </Text>
                            {card.isAbsent ? (
                                <Text size="sm" c="dimmed">
                                    {t('Not counted towards the work time report')}
                                </Text>
                            ) : null}
                        </Group>
                    ) : null}
                    <Group justify="space-between" mt="md">
                        <Button
                            component={Link}
                            href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', {
                                id: card.shiftId,
                            })}
                            size="sm"
                        >
                            {t('View shift details')}
                        </Button>
                        {card.status === 'approved' && (
                            <Switch
                                label={t('Absent')}
                                checked={card.isAbsent}
                                onChange={handleToggleAbsentClick}
                                color="orange"
                            />
                        )}
                    </Group>
                </Stack>
            </Modal>

            <Modal
                opened={confirmModalOpened}
                onClose={() => setConfirmModalOpened(false)}
                title={
                    needsCheckInWarning
                        ? t('Warning: this shift already has attendance')
                        : isConfirmingAbsent
                          ? t('Confirm absence')
                          : t('Confirm attendance')
                }
            >
                <Stack>
                    {needsCheckInWarning ? (
                        <Alert color="orange" icon={<LuTriangleAlert />}>
                            <Stack gap="xs">
                                <Text size="sm">
                                    {t(
                                        'This employee already has a recorded check-in for this shift. Marking it as absent removes its hours from the work time report and the salary.',
                                    )}
                                </Text>
                                <Text size="sm" fw={500}>
                                    {t('Please double check that this is not a mistake before confirming.')}
                                </Text>
                            </Stack>
                        </Alert>
                    ) : (
                        <Text>
                            {isConfirmingAbsent
                                ? t('Are you sure you want to mark this shift as absent?')
                                : t('Are you sure you want to mark this shift as present?')}
                        </Text>
                    )}
                    <Group justify="flex-end">
                        <Button variant="outline" onClick={() => setConfirmModalOpened(false)}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            color={needsCheckInWarning ? 'red' : undefined}
                            onClick={handleConfirmAbsent}
                            loading={toggleAbsentMutation.isPending}
                        >
                            {t('Confirm')}
                        </Button>
                    </Group>
                </Stack>
            </Modal>
        </>
    );
};

export default ShiftInfoModal;
