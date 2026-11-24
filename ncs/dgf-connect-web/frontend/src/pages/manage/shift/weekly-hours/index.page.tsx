import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileSpreadsheet } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai';
import RouteConfig from '@/configs/route.config';
import { ResidenceStatusEnum } from '@/enums/residence_status.enum';
import { WorkLoadStatusEnum } from '@/enums/work_load_status.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable, { ColumnDefinition, headerWrapClassName } from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { masterAtom } from '@/pages/global.state';
import { formatShortDate } from '@/pages/manage/shift/partials/weekly_work_load';
import WeeklyHoursCell from '@/pages/manage/shift/weekly-hours/partials/weekly_hours_cell';
import { usePermissions } from '@/hooks/usePermission';
import {
    WeeklyWorkHoursRow,
    exportWeeklyWorkHours,
    useGetWeeklyWorkHoursQuery,
} from '@/pages/manage/shift/weekly-hours/api';

const DEFAULT_RANGE_DAYS = 10;
const MAX_RANGE_DAYS = 35;
const DAYS_PER_WINDOW = 7;

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['SHIFT_LIST', 'SHIFT_EXPORT', 'USER_LIST']);
    const [masterData] = useAtom(masterAtom);
    const [isExporting, setIsExporting] = useState(false);
    const now = DateHelper.now();
    const defaultFrom = now.subtract(DEFAULT_RANGE_DAYS - 1, 'day');
    const defaultTo = now;

    const queryDates = router.query.dates;
    const hasValidDates =
        Array.isArray(queryDates) && queryDates.length === 2 && queryDates.every((date) => Boolean(date));
    const effectiveQuery = hasValidDates
        ? router.query
        : {
              ...router.query,
              dates: [defaultFrom.format(DATE_STORE_FORMAT), defaultTo.format(DATE_STORE_FORMAT)],
          };
    const { data, isPending, isError, error } = useGetWeeklyWorkHoursQuery(
        effectiveQuery,
        perms.SHIFT_LIST && router.isReady,
    );

    useEffect(() => {
        if (!isError) return;
        const isPeriodTooLong = error instanceof ApiError && Boolean(error.data?.dates);
        NotificationHelper.showError(
            isPeriodTooLong
                ? t('The date range can cover at most {{days}} days', { days: MAX_RANGE_DAYS })
                : t('Could not load weekly work hours'),
        );
    }, [isError, error, t]);

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'shift',
    );

    const handleExport = async () => {
        setIsExporting(true);
        try {
            await exportWeeklyWorkHours(effectiveQuery);
        } catch {
            NotificationHelper.showError(t('Could not export'));
        } finally {
            setIsExporting(false);
        }
    };

    const weeks = data?.data?.weeks;
    const columns = useMemo<ColumnDefinition<WeeklyWorkHoursRow>[]>(
        () => [
            {
                key: 'user',
                name: t('Employee'),
                width: 200,
                render: (row) => <UserWithAvatar user={row.user} isLink={Boolean(perms.USER_LIST)} isAvatarLink />,
            },
            {
                key: 'residence_status',
                name: t('Residence status'),
                width: 130,
                titleClassName: headerWrapClassName,
                render: (row) =>
                    row.residence_status ? EnumHelper.getText(ResidenceStatusEnum, row.residence_status) : '-',
            },
            ...(weeks ?? []).map(
                (week, index): ColumnDefinition<WeeklyWorkHoursRow> => ({
                    key: `week_${index}`,
                    name: `${formatShortDate(week.start, t)} ~ ${formatShortDate(week.end, t)}`,
                    width: 125,
                    titleClassName: headerWrapClassName,
                    render: (row) =>
                        row.weeks[index] ? (
                            <WeeklyHoursCell
                                summary={row.weeks[index]}
                                days={row.days.slice(index, index + DAYS_PER_WINDOW)}
                            />
                        ) : (
                            '-'
                        ),
                }),
            ),
        ],
        [perms.USER_LIST, t, weeks],
    );

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_SHIFT_LIST.path}>
                    {t('Manage shifts')}
                </Anchor>
                <Text>{t('Weekly work hours')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Weekly work hours')}
                </Title>
                <Button
                    leftSection={<LuFileSpreadsheet />}
                    loading={isExporting}
                    disabled={!perms.SHIFT_EXPORT || !(data?.data?.total || 0)}
                    onClick={handleExport}
                >
                    {t('Export excel')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    dates: [defaultFrom.toDate(), defaultTo.toDate()],
                    staff_no: '',
                    residence_statuses: [],
                    workplace_id: null,
                    load_status: '',
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Date range'),
                        name: 'dates',
                    },
                    {
                        id: 'staff_no',
                        type: 'input-text',
                        label: t('Employee ID'),
                        name: 'staff_no',
                    },
                    {
                        id: 'residence_statuses',
                        type: 'select',
                        label: t('Residence status'),
                        name: 'residence_statuses',
                        options: EnumHelper.toSelectArray(ResidenceStatusEnum),
                        isMulti: true,
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
                    },
                    {
                        id: 'load_status',
                        type: 'select',
                        label: t('Work-hour status'),
                        name: 'load_status',
                        options: EnumHelper.toSelectArray(WorkLoadStatusEnum),
                        showAll: true,
                        allValue: '',
                        isClearable: false,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <Flex gap="md" align="center" wrap="wrap" mb="sm">
                    {Object.values(WorkLoadStatusEnum).map((status) => (
                        <Badge key={status.value} variant="light" color={status.color}>
                            {EnumHelper.getText(WorkLoadStatusEnum, status.value)}
                        </Badge>
                    ))}
                    <Text size="xs" c="dimmed">
                        {t(
                            'Each column is a 7-day window starting one day later than the previous one. Hours use actual attendance when checked out, otherwise the planned shift. * = employee-specific limit.',
                        )}
                    </Text>
                </Flex>
                <DDataTable
                    columns={columns}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.SHIFT_LIST}
                    pinFirstColumn
                />
            </Paper>
        </>
    );
};

export default IndexPage;
