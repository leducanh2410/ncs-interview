import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuEye, LuFileSpreadsheet } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Badge, Breadcrumbs, Button, Flex, Group, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { AdminAttendanceDailySummary } from '@/types/models/admin_attendance_session';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { ATTENDANCE_ADMIN_PERMISSIONS } from '@/pages/admin/attendance/constants';
import AttendanceExportModal from '@/pages/admin/attendance/partials/export_modal';
import { useGetAttendanceDailyQuery } from '@/pages/admin/attendance/api';

const AdminAttendanceIndexPage = () => {
    const { t } = useTranslation();
    const { query, push, isReady } = useRouter();

    const now = DateHelper.now();
    const from = now.subtract(7, 'days');

    // The search box only writes its default date range into the URL once the user submits or
    // resets it, so until then fall back to the same last-7-days default here.
    const effectiveQuery = query.dates
        ? query
        : {
              ...query,
              dates: [from.format('YYYY-MM-DD'), now.format('YYYY-MM-DD')],
          };

    // `router.query` is only the real, URL-derived query once `isReady` is true — before that
    // (e.g. on first hydration of this statically-exported page) it's `{}`, which would make
    // `effectiveQuery` fall back to the default range and drop any `user_id`/`page_size` already
    // in the URL. Wait for `isReady` so we never fetch with that incomplete, throwaway query.
    const { data, isLoading } = useGetAttendanceDailyQuery(effectiveQuery, isReady);
    const [isExportOpen, { open: openExport, close: closeExport }] = useDisclosure(false);
    // Memoised: the modal resets its selection whenever this prop changes identity.
    const filteredUserIds = React.useMemo(
        () => (typeof query.user_id === 'string' ? [query.user_id] : []),
        [query.user_id],
    );

    const openDay = (row: AdminAttendanceDailySummary) => {
        push({
            pathname: RouteConfig.ADMIN_ATTENDANCE_DAY_DETAIL.path,
            query: { user_id: row.user_id, date: row.work_date },
        });
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Admin attendance')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Admin attendance')}
                </Title>
                <Button leftSection={<LuFileSpreadsheet />} onClick={openExport}>
                    {t('Export excel')}
                </Button>
            </Flex>

            <AttendanceExportModal opened={isExportOpen} onClose={closeExport} defaultUserIds={filteredUserIds} />

            <CommonSearchBox
                initialValues={{
                    dates: [from.toDate(), now.toDate()],
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
                        id: 'user_id',
                        type: 'select-remote',
                        label: t('Admin'),
                        name: 'user_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                        extraParams: { permission: ATTENDANCE_ADMIN_PERMISSIONS },
                    },
                ]}
            />

            <Paper p="md" mt="md">
                <DDataTable<AdminAttendanceDailySummary>
                    data={data?.data || []}
                    isFetching={isLoading}
                    columns={[
                        {
                            name: t('Date'),
                            key: 'work_date',
                            width: 120,
                            render: (row) => DateHelper.parseAndFormat(row.work_date),
                        },
                        {
                            name: t('Admin'),
                            key: 'admin',
                            width: 320,
                            render: (row) => (row.user ? <UserWithAvatar user={row.user} isShowStaffNo /> : '-'),
                        },
                        {
                            name: t('Number of workplaces'),
                            key: 'workplace_count',
                            align: 'center',
                            width: 170,
                            render: (row) => row.workplace_count,
                        },
                        {
                            name: `${t('Total hours')} (h)`,
                            key: 'total_hours',
                            align: 'center',
                            width: 130,
                        },
                        {
                            name: t('Warning'),
                            key: 'has_missing_checkout',
                            align: 'center',
                            width: 140,
                            render: (row) => (
                                <Group gap={4} justify="center" wrap="nowrap">
                                    {row.has_missing_checkout && <Badge color="yellow">{t('Missing check-out')}</Badge>}
                                    {row.has_invalidated_session && <Badge color="red">{t('Passed deadline')}</Badge>}
                                </Group>
                            ),
                        },
                        {
                            name: t('Detail'),
                            key: 'action',
                            align: 'center',
                            width: 100,
                            render: (row) => (
                                <ActionIcon variant="subtle" color="blue" onClick={() => openDay(row)}>
                                    <LuEye size={16} />
                                </ActionIcon>
                            ),
                        },
                    ]}
                />
            </Paper>
        </>
    );
};

export default AdminAttendanceIndexPage;
