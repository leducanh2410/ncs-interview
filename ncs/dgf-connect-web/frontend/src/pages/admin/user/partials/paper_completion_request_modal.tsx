import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Alert, Anchor, Badge, Button, Center, Flex, Loader, Modal, ScrollArea, Table, Text } from '@mantine/core';
import { UserUpcomingShift } from '@/types/models/user_upcoming_shift';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';

/** How far ahead the warning looks. Shifts beyond it are too far out to weigh here. */
export const COMPLETION_SHIFT_WINDOW_DAYS = 7;

const statusOf = (status: string) => {
    return Object.values(ShiftUserStatusEnum).find((item) => item.value === status);
};

/**
 * Confirms sending an approved file back for completion.
 *
 * The file returns to `pending`, which closes check-in and shift registration until the
 * employee files what their residence status requires. Their assigned shifts are left
 * alone — they may complete the file the same day — so the shifts are shown rather than
 * cancelled, and the admin decides whether the timing works.
 */
const PaperCompletionRequestModal = ({
    opened,
    shifts,
    shiftsLoading,
    shiftsFailed,
    loading,
    message,
    onConfirm,
    onCancel,
}: Props) => {
    const { t } = useTranslation();

    return (
        <Modal opened={opened} onClose={onCancel} title={t('Request document completion')} size="lg">
            <Alert color="yellow" mb="md">
                <Text size="sm">
                    {message ??
                        t(
                            'The employee will not be able to check in or register for shifts until they file the missing documents.',
                        )}
                </Text>
            </Alert>
            {shiftsLoading ? (
                <Center py="md">
                    <Loader size="sm" />
                </Center>
            ) : shiftsFailed ? (
                <Alert color="red">
                    <Text size="sm">{t('The upcoming shifts could not be loaded.')}</Text>
                </Alert>
            ) : shifts.length > 0 ? (
                <>
                    <Text size="sm" fw={500} mb={8}>
                        {t('Shifts in the next {{days}} days ({{count}})', {
                            days: COMPLETION_SHIFT_WINDOW_DAYS,
                            count: shifts.length,
                        })}
                    </Text>
                    <ScrollArea.Autosize mah={320}>
                        <Table striped withTableBorder>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t('Date')}</Table.Th>
                                    <Table.Th>{t('Workplace')}</Table.Th>
                                    <Table.Th>{t('Time')}</Table.Th>
                                    <Table.Th>{t('Status')}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {shifts.map((shift) => {
                                    const status = statusOf(shift.status);
                                    return (
                                        <Table.Tr key={shift.id}>
                                            <Table.Td>{DateHelper.parseAndFormat(shift.date)}</Table.Td>
                                            <Table.Td>{shift.workplace_name}</Table.Td>
                                            <Table.Td>
                                                <Anchor
                                                    component={Link}
                                                    href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', {
                                                        id: shift.shift_id,
                                                    })}
                                                    target="_blank"
                                                >
                                                    {`${shift.start_time} - ${shift.end_time}`}
                                                </Anchor>
                                            </Table.Td>
                                            <Table.Td>
                                                <Badge color={status?.color}>
                                                    {status ? t(status.text) : shift.status}
                                                </Badge>
                                            </Table.Td>
                                        </Table.Tr>
                                    );
                                })}
                            </Table.Tbody>
                        </Table>
                    </ScrollArea.Autosize>
                </>
            ) : (
                <Text size="sm">
                    {t('No shifts in the next {{days}} days.', { days: COMPLETION_SHIFT_WINDOW_DAYS })}
                </Text>
            )}

            <Flex justify="flex-end" mt={16} gap={8}>
                <Button variant="filled" color="gray" onClick={onCancel} disabled={loading}>
                    {t('Cancel')}
                </Button>
                {/* A failed shift query leaves the decision with the admin, who is told the
                    roster is unknown — only an unanswered one holds the button back */}
                <Button variant="filled" color="yellow" onClick={onConfirm} loading={loading || shiftsLoading}>
                    {t('Request completion')}
                </Button>
            </Flex>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    shifts: UserUpcomingShift[];
    /** The shift query is still in flight, so `shifts` says nothing yet */
    shiftsLoading?: boolean;
    /** The shift query failed, so the roster is unknown rather than empty */
    shiftsFailed?: boolean;
    loading?: boolean;
    /** What the employee is being asked for, when it is not simply the missing documents */
    message?: string;
    onConfirm: () => void;
    onCancel: () => void;
};

export default PaperCompletionRequestModal;
