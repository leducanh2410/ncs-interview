import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Flex, Paper, Text, Title, Tooltip } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { useQueryClient } from '@tanstack/react-query';
import { AdminLeaveRequest } from '@/types/models/admin_leave_request';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { AdminLeaveRequestStatusEnum } from '@/enums/admin_leave_request_status.enum';
import { adminLeaveTypeSelectOptions, getAdminLeaveTypeLabel } from '@/enums/admin_leave_type.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { PermissionNameEnum } from '@/enums/permission_name.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox, { userRenderItem } from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import RejectRequestModal from '@/pages/admin/admin-leave/partials/reject_request_modal';
import RequestDetailModal from '@/pages/admin/admin-leave/partials/request_detail_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    DETAIL_QUERY_KEY,
    QUERY_KEY,
    useApproveAdminLeaveRequestMutation,
    useGetAdminLeaveRequestDetailQuery,
    useGetAdminLeaveRequestsQuery,
    useRejectAdminLeaveRequestMutation,
} from '@/pages/admin/admin-leave/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const queryClient = useQueryClient();
    const perms = usePermissions(['ADMIN_LEAVE_MANAGE']);

    const { data, isPending } = useGetAdminLeaveRequestsQuery(router.query, perms.ADMIN_LEAVE_MANAGE);
    const approveMutation = useApproveAdminLeaveRequestMutation();
    const rejectMutation = useRejectAdminLeaveRequestMutation();

    const [detailRequestId, setDetailRequestId] = useState<number | null>(null);
    const [rejectModalOpened, setRejectModalOpened] = useState(false);
    const detailQuery = useGetAdminLeaveRequestDetailQuery(detailRequestId, perms.ADMIN_LEAVE_MANAGE);

    const today = DateHelper.now();

    // The decision may have failed because another manager already approved/rejected the same
    // request; the list and the open detail modal must drop the stale Pending state either way.
    const handleDecisionError = (error: unknown) => {
        queryClient.invalidateQueries({ queryKey: QUERY_KEY });
        queryClient.invalidateQueries({ queryKey: DETAIL_QUERY_KEY });

        if (error instanceof ApiError && error.message === ErrorCodeEnum.ADMIN_LEAVE_REQUEST_NOT_PENDING.value) {
            notifications.show({ color: 'red', message: t('error|This request was already decided by someone else') });
            return true;
        }

        return false;
    };

    const handleApprove = async () => {
        if (!detailRequestId) return;
        try {
            await approveMutation.mutateAsync(detailRequestId);
            notifications.show({ color: 'teal', message: t('Successfully approved request') });
            setDetailRequestId(null);
        } catch (error) {
            if (!handleDecisionError(error)) {
                notifications.show({ color: 'red', message: t('error|Could not approve request') });
            }
        }
    };

    const handleReject = async (rejectReason: string) => {
        if (!detailRequestId) return;
        try {
            await rejectMutation.mutateAsync({ id: detailRequestId, reject_reason: rejectReason });
            notifications.show({ color: 'teal', message: t('Successfully rejected request') });
            setRejectModalOpened(false);
            setDetailRequestId(null);
        } catch (error) {
            if (!handleDecisionError(error)) {
                notifications.show({ color: 'red', message: t('error|Could not reject request') });
            }
            // Rethrown so the reason modal knows the submission failed and keeps the typed text
            // instead of clearing it, e.g. when the request was decided by someone else first.
            throw error;
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Leave requests')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Leave requests')}
            </Title>

            <CommonSearchBox
                initialValues={{
                    dates: [today.startOf('month').toDate(), today.endOf('month').toDate()],
                    requester_id: null,
                    type: 'all',
                    status: 'all',
                }}
                elements={[
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Date range'),
                        name: 'dates',
                    },
                    {
                        id: 'requester_id',
                        type: 'select-remote',
                        label: t('Requester'),
                        name: 'requester_id',
                        uri: ApiConfig.ADMIN_MASTER_USER_SEARCH,
                        renderItem: userRenderItem,
                        extraParams: { permission: PermissionNameEnum.MANAGER_AUTH.value },
                    },
                    {
                        id: 'type',
                        type: 'select',
                        label: t('Type'),
                        name: 'type',
                        options: [{ value: 'all', label: t('All') }, ...adminLeaveTypeSelectOptions()],
                        isClearable: false,
                    },
                    {
                        id: 'status',
                        type: 'select',
                        label: t('Status'),
                        name: 'status',
                        options: [
                            { value: 'all', label: t('All') },
                            ...EnumHelper.toSelectArray(AdminLeaveRequestStatusEnum),
                        ],
                        isClearable: false,
                    },
                ]}
            />

            <Paper mt="md" p="md">
                <DDataTable
                    tableKey="admin-leave-request"
                    columns={[
                        {
                            name: t('Requester'),
                            key: 'requester_name',
                            render: (row: AdminLeaveRequest) => (
                                <UserWithAvatar
                                    user={{
                                        id: row.requester_id,
                                        name: row.requester_name,
                                        staff_no: row.requester_staff_no,
                                        profile_picture: row.requester_profile_picture,
                                    }}
                                />
                            ),
                        },
                        {
                            name: t('Type'),
                            key: 'type',
                            render: (row: AdminLeaveRequest) => getAdminLeaveTypeLabel(row.type),
                        },
                        {
                            name: t('Dates'),
                            key: 'dates',
                            render: (row: AdminLeaveRequest) => (
                                <Flex align="center" gap={4}>
                                    <Text>
                                        {row.start_date
                                            ? `${DateHelper.parseAndFormat(row.start_date)} ~ ${DateHelper.parseAndFormat(row.end_date)}`
                                            : '-'}
                                    </Text>
                                    {row.has_overlap && (
                                        <Tooltip label={t('Overlaps another request from the same requester')}>
                                            <Badge color="orange" variant="light">
                                                {t('Overlap')}
                                            </Badge>
                                        </Tooltip>
                                    )}
                                </Flex>
                            ),
                        },
                        {
                            name: t('Days'),
                            key: 'leave_days',
                            render: (row: AdminLeaveRequest) => row.leave_days ?? '-',
                        },
                        {
                            name: t('Submitted at'),
                            key: 'created_at',
                            render: (row: AdminLeaveRequest) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row: AdminLeaveRequest) => (
                                <Badge color={EnumHelper.getColor(AdminLeaveRequestStatusEnum, row.status)}>
                                    {EnumHelper.getText(AdminLeaveRequestStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Decided by'),
                            key: 'decided_by_name',
                            render: (row: AdminLeaveRequest) =>
                                row.decided_by_id ? (
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('ADMIN_USER_DETAIL', { id: row.decided_by_id })}
                                    >
                                        {row.decided_by_name}
                                    </Anchor>
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Decided at'),
                            key: 'decided_at',
                            render: (row: AdminLeaveRequest) =>
                                row.decided_at
                                    ? DateHelper.parseAndFormat(row.decided_at, null, t('FORMAT_DATE_WITH_TIME'))
                                    : '-',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: AdminLeaveRequest) => (
                                <Anchor onClick={() => setDetailRequestId(row.id)}>{t('Detail')}</Anchor>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.ADMIN_LEAVE_MANAGE}
                />
            </Paper>

            {
                // Hidden (not just visually covered) while the reject dialog is open — two
                // simultaneously-opened Mantine Modals stack ambiguously, leaving the one
                // underneath unclickable. detailRequestId itself stays set so handleReject can
                // still read it, and this modal reappears once the reject dialog closes.
                !rejectModalOpened && (
                    <RequestDetailModal
                        requestId={detailRequestId}
                        detail={detailQuery.data?.data}
                        isLoading={detailQuery.isPending}
                        onClose={() => setDetailRequestId(null)}
                        onApprove={handleApprove}
                        onReject={() => setRejectModalOpened(true)}
                        isProcessing={approveMutation.isPending}
                    />
                )
            }

            <RejectRequestModal
                opened={rejectModalOpened}
                onClose={() => setRejectModalOpened(false)}
                onSubmit={handleReject}
                isProcessing={rejectMutation.isPending}
            />
        </>
    );
};

export default IndexPage;
