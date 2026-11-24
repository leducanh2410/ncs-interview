import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendarHeart, LuFileSpreadsheet, LuUpload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import SalaryDeductionImportModal from '@/pages/manage/salary/partials/salary_deduction_import_modal';
import SalaryRemainingImportModal from '@/pages/manage/salary/partials/salary_remaining_import_modal';
import { usePermissions } from '@/hooks/usePermission';
import { exportSalary, useGetSalariesQuery } from '@/pages/manage/salary/api';

const SalaryRequestIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions([
        'SALARY_LIST',
        'SALARY_IMPORT_DEDUCTION',
        'SALARY_IMPORT_REMAINING',
        'SALARY_EXPORT',
        'SALARY_PROCESS_LIST',
    ]);
    const { data, isLoading } = useGetSalariesQuery(router.query, perms.SALARY_LIST);
    const [isShowImportDeduction, { open: openImportDeduction, close: closeImportDeduction }] = useDisclosure(false);
    const [isShowImportRemaining, { open: openImportRemaining, close: closeImportRemaining }] = useDisclosure(false);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Salary')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Salary')}
                </Title>
                <Flex gap="8">
                    <Button
                        leftSection={<LuCalendarHeart />}
                        variant="outline"
                        component={Link}
                        href={RouteConfig.MANAGE_SALARY_PROCESS.path}
                        disabled={!perms.SALARY_PROCESS_LIST}
                    >
                        {t('Salary payment schedule')}
                    </Button>
                    <Button
                        leftSection={<LuUpload />}
                        variant="outline"
                        onClick={openImportDeduction}
                        color="orange"
                        disabled={!perms.SALARY_IMPORT_DEDUCTION}
                    >
                        {t('Import salary deduction')}
                    </Button>
                    <Button
                        leftSection={<LuUpload />}
                        variant="outline"
                        onClick={openImportRemaining}
                        color="orange"
                        disabled={!perms.SALARY_IMPORT_REMAINING}
                    >
                        {t('Import remaining salary')}
                    </Button>
                    <Button
                        leftSection={<LuFileSpreadsheet />}
                        disabled={!(data?.data?.total || 0) || !perms.SALARY_EXPORT}
                        onClick={async () => {
                            if (!data?.data?.data.length) {
                                return;
                            }
                            try {
                                await exportSalary(router.query);
                            } catch (e) {
                                NotificationHelper.showError(t('error|Could not export salary'));
                            }
                        }}
                    >
                        {t('Export excel')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{ month: DateHelper.now().toDate(), user_id: null }}
                elements={[
                    {
                        id: 'month',
                        type: 'date',
                        label: t('Month'),
                        name: 'month',
                        isMonth: true,
                    },
                    {
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('User'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Month'),
                            key: 'month',
                        },
                        {
                            name: t('Employee'),
                            key: 'user_id',
                            render: (data) => <UserWithAvatar user={data.user} />,
                        },
                        {
                            name: t('Total amount'),
                            key: 'total_amount',
                            render: (data) => {
                                return CommonHelper.formatNumber(data.total_amount);
                            },
                        },
                        {
                            name: t('Total deduction'),
                            key: 'total_deductions',
                            render: (data) => {
                                return CommonHelper.formatNumber(data.total_deductions);
                            },
                        },
                        {
                            name: t('Total fee'),
                            key: 'total_fee',
                            render: (data) => {
                                return CommonHelper.formatNumber(data.total_fee);
                            },
                        },
                        {
                            name: t('Work days'),
                            key: 'work_days',
                        },
                        {
                            name: t('Total work hours'),
                            key: 'total_minutes',
                            render: (data) => {
                                return CommonHelper.formatNumber(data.total_minutes / 60);
                            },
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.SALARY_LIST}
                />
            </Paper>
            <SalaryDeductionImportModal opened={isShowImportDeduction} onClose={closeImportDeduction} />
            <SalaryRemainingImportModal opened={isShowImportRemaining} onClose={closeImportRemaining} />
        </>
    );
};

export default SalaryRequestIndexPage;
