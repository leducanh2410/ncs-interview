import { useTranslation } from 'react-i18next';
import { Alert, Badge, Box, Button, Flex, Modal, Text } from '@mantine/core';
import { FixedScheduleChangeRequest } from '@/types/models/fixed_schedule_change_request';
import { FixedScheduleChangeRequestStatusEnum } from '@/enums/fixed_schedule_change_request_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';

const RequestDetailModal = ({ request, onClose, onApprove, onReject, canProcess, isProcessing }: Props) => {
    const { t } = useTranslation();

    if (!request) return null;

    const days = [...request.days].sort((a, b) => {
        if (a.day === 0) return 1;
        if (b.day === 0) return -1;
        return a.day - b.day;
    });

    const shiftLabel = (shift: FixedScheduleChangeRequest['days'][number]['current_workplace_shift']) => {
        if (!shift) return t('Day off');
        const { startTime, endTime } = DateHelper.formatWorkHour(shift.start_time, shift.end_time);
        return `${startTime} ~ ${endTime}`;
    };

    return (
        <Modal opened onClose={onClose} title={t('Change request detail')} size="lg">
            <Flex justify="space-between" align="center" mb="md">
                <Text>
                    {t('Employee')}: <b>{request.user.name}</b> — {t('Workplace')}: <b>{request.workplace.name}</b>
                </Text>
                <Badge color={EnumHelper.getColor(FixedScheduleChangeRequestStatusEnum, request.status)}>
                    {EnumHelper.getText(FixedScheduleChangeRequestStatusEnum, request.status)}
                </Badge>
            </Flex>

            <Box mb="md">
                <Flex align="center" py={4} gap="md" c="dimmed" fz="xs">
                    <Text inherit w={100}>
                        {t('Day')}
                    </Text>
                    <Text inherit w={140}>
                        {t('Shift at request time')}
                    </Text>
                    <Text inherit w={120}>
                        {t('Current position in schedule')}
                    </Text>
                    <Text inherit w={20} />
                    <Text inherit w={140}>
                        {t('Requested shift')}
                    </Text>
                </Flex>
                {days.map((day) => (
                    <Flex key={day.id} align="center" py={4} gap="md">
                        <Text tt="capitalize" w={100}>
                            {DateHelper.getDayOfWeek(day.day)}
                        </Text>
                        <Text w={140}>{shiftLabel(day.current_workplace_shift)}</Text>
                        <Text w={120} c="dimmed" truncate>
                            {day.current_workplace_position?.name ?? '-'}
                        </Text>
                        <Text w={20} ta="center">
                            →
                        </Text>
                        <Text w={140} fw={600}>
                            {shiftLabel(day.requested_workplace_shift)}
                        </Text>
                    </Flex>
                ))}
            </Box>

            {request.status === 'pending' && canProcess && (
                <>
                    <Alert color="orange" mb="md">
                        {t(
                            "Approving will apply all {{count}} changed days to the employee's fixed schedule immediately.",
                            {
                                count: days.length,
                            },
                        )}
                    </Alert>
                    <Flex justify="flex-end" gap="xs">
                        <Button color="red" variant="outline" onClick={onReject} loading={isProcessing}>
                            {t('Reject')}
                        </Button>
                        <Button color="teal" onClick={onApprove} loading={isProcessing}>
                            {t('Approve')}
                        </Button>
                    </Flex>
                </>
            )}
        </Modal>
    );
};

type Props = {
    request: FixedScheduleChangeRequest | null;
    onClose: () => void;
    onApprove: () => void;
    onReject: () => void;
    canProcess: boolean;
    isProcessing?: boolean;
};

export default RequestDetailModal;
