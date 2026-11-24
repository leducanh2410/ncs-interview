import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText, LuTriangleAlert } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { FixedScheduleChangeRequest } from '@/types/models/fixed_schedule_change_request';
import RouteConfig from '@/configs/route.config';
import { FixedScheduleChangeRequestStatusEnum } from '@/enums/fixed_schedule_change_request_status.enum';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import RequestDetailModal from '@/pages/admin/fixed-schedule/change-requests/partials/request_detail_modal';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useGetFixedScheduleChangeConfigsQuery } from '@/pages/admin/fixed-schedule/change-config/api';
import {
    exportFixedScheduleChangeRequests,
    useApproveFixedScheduleChangeRequestMutation,
    useBulkResolveFixedScheduleChangeRequestsMutation,
    useGetFixedScheduleChangeRequestsQuery,
    useRejectFixedScheduleChangeRequestMutation,
} from '@/pages/admin/fixed-schedule/change-requests/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['FIXED_SCHEDULE_CHANGE_REQUEST_LIST', 'FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS']);
    const [masterData] = useAtom(masterAtom);
    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'fixed_schedule_change_request',
    );

    const { data, isPending } = useGetFixedScheduleChangeRequestsQuery(
        router.query,
        perms.FIXED_SCHEDULE_CHANGE_REQUEST_LIST,
    );
    const configsQuery = useGetFixedScheduleChangeConfigsQuery(perms.FIXED_SCHEDULE_CHANGE_REQUEST_LIST);
    const approveMutation = useApproveFixedScheduleChangeRequestMutation();
    const rejectMutation = useRejectFixedScheduleChangeRequestMutation();
    const bulkMutation = useBulkResolveFixedScheduleChangeRequestsMutation();

    const [selectedRecords, setSelectedRecords] = useState<FixedScheduleChangeRequest[]>([]);
    const [detailRequest, setDetailRequest] = useState<FixedScheduleChangeRequest | null>(null);
    const [bulkAction, setBulkAction] = useState<'approve' | 'reject' | null>(null);

    const today = DateHelper.now().format(DATE_STORE_FORMAT);
    const workplaceDeadlineMap = useMemo(() => {
        const map = new Map<number, string | null>();
        (configsQuery.data?.data ?? []).forEach((c) => map.set(c.workplace_id, c.deadline));
        return map;
    }, [configsQuery.data]);
    const isOverduePending = (row: FixedScheduleChangeRequest) => {
        if (row.status !== 'pending') return false;
        const deadline = workplaceDeadlineMap.get(row.workplace.id);
        return !!deadline && deadline < today;
    };

    // Requirement 8.7-8.9: when exactly one workplace is selected and no explicit
    // date range is set yet, default "dates" to that workplace's current round
    // (config.updated_at -> config.deadline). Frontend-only UI convenience.
    useEffect(() => {
        const workplaceIdRaw = router.query.workplace_id;
        if (!workplaceIdRaw || Array.isArray(workplaceIdRaw) || router.query.dates) return;
        const config = configsQuery.data?.data?.find((c) => c.workplace_id === Number(workplaceIdRaw));
        if (!config || !config.updated_at || !config.deadline) return;
        const start = DateHelper.parseAndFormat(config.updated_at, null, DATE_STORE_FORMAT);
        router.replace({ query: { ...router.query, dates: [start, config.deadline] } }, undefined, { shallow: true });
    }, [router.query.workplace_id, configsQuery.data]);

    // Clear the selection whenever the filtered rows change (new search, page, or status
    // filter) so a stale selection from a previous view can't leak into a bulk action.
    useEffect(() => {
        setSelectedRecords([]);
    }, [data]);

    const handleApprove = async (request: FixedScheduleChangeRequest) => {
        try {
            await approveMutation.mutateAsync(request.id);
            notifications.show({ color: 'teal', message: t('Successfully approved request') });
            setDetailRequest(null);
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not approve request') });
        }
    };

    const handleReject = async (request: FixedScheduleChangeRequest) => {
        try {
            await rejectMutation.mutateAsync(request.id);
            notifications.show({ color: 'teal', message: t('Successfully rejected request') });
            setDetailRequest(null);
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not reject request') });
        }
    };

    const handleBulkConfirm = async () => {
        if (!bulkAction) return;
        try {
            await bulkMutation.mutateAsync({ ids: selectedRecords.map((r) => r.id), action: bulkAction });
            notifications.show({ color: 'teal', message: t('Successfully processed selected requests') });
            setSelectedRecords([]);
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not process selected requests') });
        } finally {
            setBulkAction(null);
        }
    };

    const handleExport = async () => {
        try {
            await exportFixedScheduleChangeRequests(router.query);
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not export change requests') });
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Change requests')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Change requests')}
                </Title>
                <Flex gap="xs">
                    {perms.FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS && selectedRecords.length > 0 && (
                        <>
                            <Button color="teal" onClick={() => setBulkAction('approve')}>
                                {t('Approve {{count}} requests', { count: selectedRecords.length })}
                            </Button>
                            <Button color="red" onClick={() => setBulkAction('reject')}>
                                {t('Reject {{count}} requests', { count: selectedRecords.length })}
                            </Button>
                        </>
                    )}
                    <Button leftSection={<LuFileText />} variant="light" onClick={handleExport}>
                        {t('Export excel')}
                    </Button>
                </Flex>
            </Flex>
            <CommonSearchBox
                // Force a remount once the "dates" default gets injected into the URL after
                // mount (see the effect above): CommonSearchBox only reads the URL into the
                // form on its first render, so without this the form would submit without the
                // default range and silently drop it from the query.
                key={Array.isArray(router.query.dates) ? router.query.dates.join(',') : (router.query.dates ?? '')}
                initialValues={{ workplace_id: null, employee_name: '', status: 'pending' }}
                elements={[
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: WorkplaceHelper.toSelectOptions(accessibleWorkplaces, t),
                        isSearchable: true,
                    },
                    {
                        id: 'employee_name',
                        type: 'input-text',
                        label: t('Employee'),
                        name: 'employee_name',
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: [
                            { value: 'all', label: t('All') },
                            ...EnumHelper.toSelectArray(FixedScheduleChangeRequestStatusEnum),
                        ],
                        isClearable: false,
                    },
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Submitted date range'),
                        name: 'dates',
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable
                    tableKey="fixed-schedule-change-request"
                    columns={[
                        {
                            name: t('Employee'),
                            key: 'user',
                            render: (row: FixedScheduleChangeRequest) => <UserWithAvatar user={row.user as any} />,
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (row: FixedScheduleChangeRequest) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: row.workplace.id })}
                                >
                                    {row.workplace.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Changed days'),
                            key: 'days',
                            render: (row: FixedScheduleChangeRequest) =>
                                t('{{count}} days', { count: row.days.length }),
                        },
                        {
                            name: t('Submitted at'),
                            key: 'created_at',
                            render: (row: FixedScheduleChangeRequest) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Registration deadline'),
                            key: 'deadline',
                            render: (row: FixedScheduleChangeRequest) => {
                                const deadline = workplaceDeadlineMap.get(row.workplace.id);
                                if (!deadline) return '-';
                                const overdue = isOverduePending(row);
                                return (
                                    <Flex align="center" gap={4}>
                                        {overdue && <LuTriangleAlert color="var(--mantine-color-red-6)" size={14} />}
                                        <Text c={overdue ? 'red' : undefined} fw={overdue ? 700 : undefined}>
                                            {DateHelper.parseAndFormat(deadline)}
                                        </Text>
                                    </Flex>
                                );
                            },
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row: FixedScheduleChangeRequest) => (
                                <Flex align="center" gap={4}>
                                    <Badge
                                        color={EnumHelper.getColor(FixedScheduleChangeRequestStatusEnum, row.status)}
                                    >
                                        {EnumHelper.getText(FixedScheduleChangeRequestStatusEnum, row.status)}
                                    </Badge>
                                    {isOverduePending(row) && (
                                        <Badge color="red" variant="filled">
                                            {t('Overdue')}
                                        </Badge>
                                    )}
                                </Flex>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: FixedScheduleChangeRequest) => (
                                <Anchor onClick={() => setDetailRequest(row)}>{t('Detail')}</Anchor>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    selectedRecords={selectedRecords}
                    onSelectedRecordsChange={setSelectedRecords}
                    isRecordSelectable={(row: FixedScheduleChangeRequest) => row.status === 'pending'}
                    noPermission={!perms.FIXED_SCHEDULE_CHANGE_REQUEST_LIST}
                    rowStyle={(row: FixedScheduleChangeRequest) =>
                        isOverduePending(row) ? { backgroundColor: 'var(--mantine-color-red-0)' } : undefined
                    }
                />
            </Paper>

            <RequestDetailModal
                request={detailRequest}
                onClose={() => setDetailRequest(null)}
                onApprove={() => detailRequest && handleApprove(detailRequest)}
                onReject={() => detailRequest && handleReject(detailRequest)}
                canProcess={perms.FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS}
                isProcessing={approveMutation.isPending || rejectMutation.isPending}
            />

            <DConfirmModal
                opened={bulkAction !== null}
                title={bulkAction === 'approve' ? t('Approve selected requests') : t('Reject selected requests')}
                message={
                    bulkAction === 'approve'
                        ? t('All changed days in the {{count}} selected requests will be applied immediately.', {
                              count: selectedRecords.length,
                          })
                        : t('Reject {{count}} selected requests?', { count: selectedRecords.length })
                }
                onConfirm={handleBulkConfirm}
                onCancel={() => setBulkAction(null)}
                confirmButtonLabel={bulkAction === 'approve' ? t('Approve') : t('Reject')}
                confirmButtonColor={bulkAction === 'approve' ? 'teal' : 'red'}
                isProcessing={bulkMutation.isPending}
            />
        </>
    );
};

export default IndexPage;
