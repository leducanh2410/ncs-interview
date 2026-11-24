import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Paper, Text, Title } from '@mantine/core';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import RouteHelper from '@/helpers/route.helper';
import UrlHelper from '@/helpers/url.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { usePermissions } from '@/hooks/usePermission';
import { useGetSpoofedCheckInsQuery } from '@/pages/admin/check-in-spoof/api';

const AdminCheckInSpoofIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['CHECK_IN_SPOOF_LIST']);
    const { data, isPending } = useGetSpoofedCheckInsQuery(router.query, perms.CHECK_IN_SPOOF_LIST);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Spoofed Check-Ins')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Spoofed Check-Ins')}
            </Title>
            <CommonSearchBox
                initialValues={{ user_id: null }}
                elements={[
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
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Date'),
                            key: 'date',
                            render: (data) => {
                                return DateHelper.parseAndFormat(data.check_in.shift.date);
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
                                            id: data.check_in.shift.workplace.id,
                                        })}
                                    >
                                        {data.check_in.shift.workplace.name}
                                    </Anchor>
                                );
                            },
                        },
                        {
                            name: t('Shift'),
                            key: 'shift_time',
                            render: (data) => {
                                const { startTime, endTime } = DateHelper.formatWorkHour(
                                    data.check_in.shift.workplace_shift.start_time,
                                    data.check_in.shift.workplace_shift.end_time,
                                );
                                return (
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('MANAGE_SHIFT_DETAIL', {
                                            id: data.check_in.shift.id,
                                        })}
                                    >
                                        {startTime} ~ {endTime}
                                    </Anchor>
                                );
                            },
                        },
                        {
                            name: t('User'),
                            key: 'user',
                            render: (data) =>
                                data.check_in?.user ? (
                                    <UserWithAvatar user={data.check_in.user} isShowStaffNo={true} />
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Type'),
                            key: 'is_check_in',
                            render: (data) => (data.is_check_in ? t('Check-In') : t('Check-Out')),
                        },
                        {
                            name: t('Spoofing Likelihood'),
                            key: 'spoofed_confidence',
                            render: (data) => `${(data.spoofed_confidence * 100).toFixed(1)}%`,
                        },
                        {
                            name: t('Updated At'),
                            key: 'updated_at',
                            render: (data) =>
                                DateHelper.parseAndFormat(data.updated_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Image'),
                            key: 'image_path',
                            render: (data) =>
                                data.image_path ? (
                                    <Anchor href={UrlHelper.getStaticUrl(data.image_path)} target="_blank">
                                        {t('View')}
                                    </Anchor>
                                ) : (
                                    '-'
                                ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>
        </>
    );
};

export default AdminCheckInSpoofIndexPage;
