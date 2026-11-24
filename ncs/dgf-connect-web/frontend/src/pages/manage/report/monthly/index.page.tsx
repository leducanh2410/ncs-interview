import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Paper, Text, Title } from '@mantine/core';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { usePermissions } from '@/hooks/usePermission';
import { useGetMonthlyReportsQuery } from '@/pages/manage/report/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['WORK_REPORT_LIST']);
    const { data, isLoading } = useGetMonthlyReportsQuery(router.query, perms.WORK_REPORT_LIST);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_REPORT_LIST.path}>
                    {t('Report')}
                </Anchor>
                <Text>{t('Monthly report')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Monthly report')}
            </Title>
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
                        { name: 'ID', key: 'id', align: 'center' },
                        {
                            name: t('Employee'),
                            key: 'employee',
                            render: (data) => <UserWithAvatar user={data} />,
                        },
                        {
                            name: t('Late arrival'),
                            key: 'late_minutes',
                            render: (data) => {
                                return (
                                    <>
                                        {data.late_minutes ? (
                                            <>
                                                <div>{t('{{time}} times', { time: data.late_count })}</div>
                                                <div>
                                                    {t('Total: {{minute}} minutes', {
                                                        minute: data.late_minutes,
                                                    })}
                                                </div>
                                            </>
                                        ) : (
                                            '-'
                                        )}
                                    </>
                                );
                            },
                        },
                        {
                            name: t('Early leave'),
                            key: 'early_minutes',
                            render: (data) => {
                                return (
                                    <>
                                        {data.early_minutes ? (
                                            <>
                                                <div>{t('{{time}} times', { time: data.early_count })}</div>
                                                <div>
                                                    {t('Total: {{minute}} minutes', {
                                                        minute: data.early_minutes,
                                                    })}
                                                </div>
                                            </>
                                        ) : (
                                            '-'
                                        )}
                                    </>
                                );
                            },
                        },
                        {
                            name: t('Absent'),
                            key: 'is_absent',
                            render: (data) =>
                                data.absent_count > 0
                                    ? t('{{time}} times', {
                                          time: data.absent_count,
                                      })
                                    : '-',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                />
            </Paper>
        </>
    );
};
export default IndexPage;
