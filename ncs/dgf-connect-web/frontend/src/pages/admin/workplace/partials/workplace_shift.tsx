import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Button, Flex, Paper, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { WorkplaceShiftWithRelation } from '@/types/models/workplace_shift';
import DateHelper from '@/helpers/date.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import WorkplaceShiftCreateUpdate from '@/pages/admin/workplace/partials/workplace_shift_create_update';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteWorkplaceShiftMutation, useGetWorkplaceShiftsQuery } from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
};

const WorkplaceShiftList = ({ workplaceId }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'WORKPLACE_SHIFT_LIST',
        'WORKPLACE_SHIFT_CREATE',
        'WORKPLACE_SHIFT_EDIT',
        'WORKPLACE_SHIFT_DELETE',
    ]);
    const { data, isLoading } = useGetWorkplaceShiftsQuery(workplaceId, perms.WORKPLACE_SHIFT_LIST);
    const deleteMutation = useDeleteWorkplaceShiftMutation(workplaceId);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [currentShift, setCurrentShift] = useState<WorkplaceShiftWithRelation | null>(null);
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);

    return (
        <Paper flex={1} p="md">
            <Flex justify="space-between" align="center" mb="16">
                <Text size="md" fw={700}>
                    {t('Shift')}
                </Text>
                <Button
                    size="sm"
                    h={32}
                    leftSection={<LuPlus />}
                    disabled={!perms.WORKPLACE_SHIFT_CREATE}
                    onClick={() => {
                        setCurrentShift(null);
                        createUpdateModalAction.open();
                    }}
                >
                    {t('Add')}
                </Button>
            </Flex>
            <DDataTable
                columns={[
                    { name: 'ID', key: 'id', align: 'center' },
                    {
                        name: t('Work Time'),
                        key: 'work_time',
                        render: (data) => {
                            const { startTime, endTime } = DateHelper.formatWorkHour(data.start_time, data.end_time);
                            return `${startTime} ~ ${endTime}`;
                        },
                    },
                    {
                        name: t('Break Time'),
                        key: 'break_time',
                        render: (data) => {
                            if (!data.start_break_time || !data.end_break_time) return '-';
                            const { startBreakTime, endBreakTime } = DateHelper.formatWorkHour(
                                data.start_time,
                                data.end_time,
                                data.start_break_time,
                                data.end_break_time,
                            );
                            return `${startBreakTime} ~ ${endBreakTime} (${data.break_duration} ${t('minutes')})`;
                        },
                    },
                    {
                        name: t('Salary per hour'),
                        key: 'salary_per_hour',
                        render: (rowData) => (rowData.salary_per_hour === null ? '-' : rowData.salary_per_hour),
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData) => (
                            <Flex>
                                <ActionIcon
                                    variant="subtle"
                                    color="blue"
                                    onClick={() => {
                                        setCurrentShift(rowData);
                                        createUpdateModalAction.open();
                                    }}
                                    disabled={!perms.WORKPLACE_SHIFT_EDIT}
                                >
                                    <LuPencilLine size={16} />
                                </ActionIcon>
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => setDeleteId(rowData.id)}
                                    disabled={!perms.WORKPLACE_SHIFT_DELETE}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            </Flex>
                        ),
                        width: 100,
                    },
                ]}
                data={data && data.data ? data.data : []}
                isFetching={isLoading}
            />
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete shift')}
                message={t('Are you sure you want to delete this shift?')}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={deleteMutation.isPending}
                onCancel={() => setDeleteId(null)}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId.toString());
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted shift'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete shift'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
            />
            <WorkplaceShiftCreateUpdate
                workplaceId={workplaceId}
                shift={currentShift}
                opened={createUpdateModalOpened}
                onClose={() => {
                    createUpdateModalAction.close();
                    setCurrentShift(null);
                }}
            />
        </Paper>
    );
};
export default WorkplaceShiftList;
