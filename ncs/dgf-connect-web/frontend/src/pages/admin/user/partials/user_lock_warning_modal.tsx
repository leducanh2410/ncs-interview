import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Alert, Anchor, Badge, Button, Flex, Modal, ScrollArea, Table, Text } from '@mantine/core';
import { UserUpcomingShift } from '@/types/models/user_upcoming_shift';
import { ShiftUserStatusEnum } from '@/enums/shift_user_status.enum';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';

const statusOf = (status: string) => {
    return Object.values(ShiftUserStatusEnum).find((item) => item.value === status);
};

const UserLockWarningModal = ({ opened, shifts, onConfirm, onCancel }: Props) => {
    const { t } = useTranslation();

    return (
        <Modal opened={opened} onClose={onCancel} title={t('Lock account')} size="lg">
            <Alert color="orange" mb="md">
                <Text size="sm">
                    {t(
                        'This employee still has {{count}} upcoming shift(s). Locking the account will reject all of them.',
                        { count: shifts.length },
                    )}
                </Text>
            </Alert>
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
                                            href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', { id: shift.shift_id })}
                                            target="_blank"
                                        >
                                            {`${shift.start_time} - ${shift.end_time}`}
                                        </Anchor>
                                    </Table.Td>
                                    <Table.Td>
                                        <Badge color={status?.color}>{status ? t(status.text) : shift.status}</Badge>
                                    </Table.Td>
                                </Table.Tr>
                            );
                        })}
                    </Table.Tbody>
                </Table>
            </ScrollArea.Autosize>
            <Flex justify="flex-end" mt={16} gap={8}>
                <Button variant="filled" color="gray" onClick={onCancel}>
                    {t('Cancel')}
                </Button>
                <Button variant="filled" color="red" onClick={onConfirm}>
                    {t('Continue to lock')}
                </Button>
            </Flex>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    shifts: UserUpcomingShift[];
    onConfirm: () => void;
    onCancel: () => void;
};

export default UserLockWarningModal;
