import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { SalaryProcess } from '@/types/models/salary';
import RouteConfig from '@/configs/route.config';
import { SalaryProcessStatusEnum } from '@/enums/salary_process_status.enum';
import DateHelper, { DATE_STORE_FORMAT_MONTH, DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import SalaryProcessCreateUpdateModal from '@/pages/manage/salary/process/partials/salary_process_create_update_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteSalaryProcessMutation, useGetSalaryProcessesQuery } from '@/pages/manage/salary/process/api';

const SalaryProcessIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions([
        'SALARY_PROCESS_LIST',
        'SALARY_PROCESS_CREATE',
        'SALARY_PROCESS_EDIT',
        'SALARY_PROCESS_DELETE',
    ]);
    const { data, isLoading } = useGetSalaryProcessesQuery(router.query, perms.SALARY_PROCESS_LIST);
    const [masterData] = useAtom(masterAtom);
    const [isShowCreateUpdate, { open: openCreateUpdate, close: closeCreateUpdate }] = useDisclosure(false);
    const [currentSalaryProcess, setCurrentSalaryProcess] = React.useState<SalaryProcess | null>(null);
    const [deleteId, setDeleteId] = React.useState<number | null>(null);
    const deleteMutation = useDeleteSalaryProcessMutation();

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Salary payment schedule')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Salary payment schedule')}
                </Title>
                <Flex gap="8">
                    <Button leftSection={<LuPlus />} onClick={openCreateUpdate} disabled={!perms.SALARY_PROCESS_CREATE}>
                        {t('Create')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{ month: null, user_id: null }}
                elements={[
                    {
                        id: 'month',
                        type: 'date',
                        label: t('Month'),
                        name: 'month',
                        isMonth: true,
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: masterData.workplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isSearchable: true,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Month'),
                            key: 'month',
                            render: (data) => {
                                return DateHelper.parseAndFormat(
                                    data.month,
                                    DATE_STORE_FORMAT_MONTH,
                                    t('FORMAT_MONTH'),
                                );
                            },
                        },
                        {
                            name: t('Scheduled at'),
                            key: 'scheduled_at',
                            render: (data) => {
                                return DateHelper.parseAndFormat(
                                    data.scheduled_at,
                                    DATE_STORE_FORMAT_WITH_TIME,
                                    t('FORMAT_TIMESTAMP'),
                                );
                            },
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (data) => data.workplace.name,
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                        },
                        {
                            name: t('Priority'),
                            key: 'priority',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (data) => (
                                <Flex>
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => {
                                            setCurrentSalaryProcess(data);
                                            openCreateUpdate();
                                        }}
                                        disabled={
                                            data.status !== SalaryProcessStatusEnum.PENDING.value ||
                                            !perms.SALARY_PROCESS_EDIT
                                        }
                                    >
                                        <LuPencilLine size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(data.id)}
                                        disabled={
                                            data.status !== SalaryProcessStatusEnum.PENDING.value ||
                                            !perms.SALARY_PROCESS_DELETE
                                        }
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                />
            </Paper>
            <SalaryProcessCreateUpdateModal
                salaryProcess={currentSalaryProcess}
                opened={isShowCreateUpdate}
                onClose={closeCreateUpdate}
            />
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete salary payment schedule')}
                message={t(
                    'Are you sure you want to delete this salary payment schedule? This action cannot be undone.',
                )}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId);
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted salary payment schedule'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete salary payment schedule'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default SalaryProcessIndexPage;
