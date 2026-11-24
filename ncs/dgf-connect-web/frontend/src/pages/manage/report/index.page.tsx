import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFolders, LuX } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai/index';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useGetReportsQuery } from '@/pages/manage/report/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const { push, query } = useRouter();
    const perms = usePermissions(['WORK_REPORT_LIST']);
    const { data, isLoading } = useGetReportsQuery(query, perms.WORK_REPORT_LIST);
    const [masterData] = useAtom(masterAtom);

    const now = DateHelper.now();
    const from = now.subtract(7, 'days');

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'work_report',
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Report')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Report')}
                </Title>
                <Button leftSection={<LuFolders />} onClick={() => push(RouteConfig.MANAGE_MONTHLY_REPORT_LIST.path)}>
                    {t('View Monthly report')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    dates: [from.toDate(), now.toDate()],
                    workplace_id: '0',
                    user_id: null,
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Date range'),
                        name: 'dates',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: accessibleWorkplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isSearchable: true,
                        showAll: true,
                        allValue: '0',
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
                            name: t('Date'),
                            key: 'shift.date',
                            render: (data) => {
                                return DateHelper.parseAndFormat(data.shift_user.shift.date);
                            },
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (data) => {
                                return (
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', {
                                            id: data.shift_user.shift.workplace.id,
                                        })}
                                    >
                                        {data.shift_user.shift.workplace.name}
                                    </Anchor>
                                );
                            },
                        },
                        {
                            name: t('Shift'),
                            key: 'shift_time',
                            render: (data) => {
                                const { startTime, endTime } = DateHelper.formatWorkHour(
                                    data.shift_user.shift.workplace_shift.start_time,
                                    data.shift_user.shift.workplace_shift.end_time,
                                );
                                return (
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', {
                                            id: data.shift_user.shift_id,
                                        })}
                                    >
                                        {startTime} ~ {endTime}
                                    </Anchor>
                                );
                            },
                        },
                        {
                            name: t('Employee'),
                            key: 'employee',
                            render: (data) => <UserWithAvatar user={data.user} />,
                        },
                        {
                            name: t('Late arrival'),
                            key: 'late_minutes',
                            render: (data) =>
                                data.late_minutes ? t('{{minute}} minutes', { minute: data.late_minutes }) : '-',
                        },
                        {
                            name: t('Early leave'),
                            key: 'early_minutes',
                            render: (data) =>
                                data.early_minutes ? t('{{minute}} minutes', { minute: data.early_minutes }) : '-',
                        },
                        {
                            name: t('Absent'),
                            key: 'is_absent',
                            render: (data) => (data.is_absent ? <LuX color="red" size={16} /> : '-'),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isLoading}
                    noPermission={!perms.WORK_REPORT_LIST}
                />
            </Paper>
        </>
    );
};
export default IndexPage;
