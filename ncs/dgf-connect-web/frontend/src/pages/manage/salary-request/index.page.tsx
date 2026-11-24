import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileSpreadsheet, LuPlus, LuUpload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import dayjs from 'dayjs';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { SalaryRequestStatusEnum } from '@/enums/salary_request_status.enum';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import SalaryRequestCreateModal from '@/pages/manage/salary-request/partials/salary_create_modal';
import SalaryTransferImportModal from '@/pages/manage/salary-request/partials/salary_transfer_import_modal';
import { usePermissions } from '@/hooks/usePermission';
import { exportSalaryRequest, useGetSalaryRequestQuery } from '@/pages/manage/salary-request/api';

const SalaryRequestIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions([
        'SALARY_REQUEST_LIST',
        'SALARY_REQUEST_CREATE',
        'SALARY_REQUEST_EXPORT',
        'SALARY_IMPORT_TRANSFER',
    ]);
    const { data, isLoading } = useGetSalaryRequestQuery(router.query, perms.SALARY_REQUEST_LIST);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [isShowImportTransfer, { open: openImportTransfer, close: closeImportTransfer }] = useDisclosure(false);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Salary request')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Salary request')}
                </Title>
                <Flex gap="8">
                    <Button
                        leftSection={<LuUpload />}
                        variant="outline"
                        onClick={openImportTransfer}
                        color="orange"
                        disabled={!perms.SALARY_IMPORT_TRANSFER}
                    >
                        {t('Import transfer info')}
                    </Button>
                    <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.SALARY_REQUEST_CREATE}>
                        {t('Create request for user')}
                    </Button>
                    <Button
                        leftSection={<LuFileSpreadsheet />}
                        disabled={!(data?.data?.total || 0) || !perms.SALARY_REQUEST_EXPORT}
                        onClick={async () => {
                            if (!data?.data?.data.length) {
                                return;
                            }
                            try {
                                await exportSalaryRequest(router.query);
                            } catch (e) {
                                NotificationHelper.showError(t('error|Could not export salary request'));
                            }
                        }}
                    >
                        {t('Export excel')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                initialValues={{ status: 'all' }}
                elements={[
                    {
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('User'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Request status'),
                        name: 'status',
                        options: EnumHelper.toSelectArray(SalaryRequestStatusEnum),
                        isClearable: false,
                        showAll: true,
                        allValue: 'all',
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Date'),
                            key: 'date',
                            render: (data) => {
                                const date = dayjs(data.created_at).tz();
                                if (!date) return '-';
                                return DateHelper.format(date);
                            },
                        },
                        {
                            name: t('Employee'),
                            key: 'user_id',
                            render: (data) => <UserWithAvatar user={data.user} />,
                        },
                        {
                            name: t('Amount'),
                            key: 'amount',
                            render: (data) => {
                                return CommonHelper.formatNumber(data.amount);
                            },
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (data) => {
                                return EnumHelper.getText(SalaryRequestStatusEnum, data.status);
                            },
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (data) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_SALARY_REQUEST_DETAIL', { id: data.id })}
                                >
                                    {t('Detail')}
                                </Anchor>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.SALARY_REQUEST_LIST}
                />
            </Paper>
            <SalaryRequestCreateModal opened={isShowCreate} onClose={closeCreate} />
            <SalaryTransferImportModal opened={isShowImportTransfer} onClose={closeImportTransfer} />
        </>
    );
};

export default SalaryRequestIndexPage;
