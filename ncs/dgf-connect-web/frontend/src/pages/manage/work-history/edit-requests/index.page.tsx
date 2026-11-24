import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useQueryClient } from '@tanstack/react-query';
import { useAtom } from 'jotai';
import { WorkHistoryEditRequest, WorkHistoryEditRequestConflict } from '@/types/models/work_history_edit_request';
import RouteConfig from '@/configs/route.config';
import { WorkHistoryEditRequestStatusEnum } from '@/enums/work_history_edit_request_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { masterAtom } from '@/pages/global.state';
import BulkRejectModal from '@/pages/manage/work-history/edit-requests/partials/bulk_reject_modal';
import { fieldLabel } from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';
import ImportModal from '@/pages/manage/work-history/edit-requests/partials/import_modal';
import ImportReviewModal from '@/pages/manage/work-history/edit-requests/partials/import_review_modal';
import RejectRequestModal from '@/pages/manage/work-history/edit-requests/partials/reject_request_modal';
import RequestDetailModal from '@/pages/manage/work-history/edit-requests/partials/request_detail_modal';
import {
    getApproveConflicts,
    getApproveErrorMessage,
    getBulkResolveErrorMessage,
    isRequestNoLongerApprovable,
} from '@/pages/manage/work-history/partials/approve_edit_request_error';
import { usePermissions } from '@/hooks/usePermission';
import { useApproveWorkHistoryEditRequestMutation } from '@/pages/manage/work-history/api';
import {
    EDIT_REQUESTS_QUERY_KEY,
    ImportPreviewRow,
    useBulkResolveWorkHistoryEditRequestsMutation,
    useGetWorkHistoryEditRequestsQuery,
    useRejectWorkHistoryEditRequestMutation,
} from '@/pages/manage/work-history/edit-requests/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const queryClient = useQueryClient();
    const perms = usePermissions(['WORK_HISTORY_EDIT_REQUEST_PROCESS']);
    const [masterData] = useAtom(masterAtom);
    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'work_history',
    );

    const { data, isPending } = useGetWorkHistoryEditRequestsQuery(
        router.query,
        perms.WORK_HISTORY_EDIT_REQUEST_PROCESS,
    );
    const approveMutation = useApproveWorkHistoryEditRequestMutation();
    const rejectMutation = useRejectWorkHistoryEditRequestMutation();
    const bulkMutation = useBulkResolveWorkHistoryEditRequestsMutation();

    const [selectedRecords, setSelectedRecords] = useState<WorkHistoryEditRequest[]>([]);
    const [detailRequest, setDetailRequest] = useState<WorkHistoryEditRequest | null>(null);
    const [conflicts, setConflicts] = useState<WorkHistoryEditRequestConflict[] | null>(null);
    const [rejectModalOpened, setRejectModalOpened] = useState(false);
    const [bulkAction, setBulkAction] = useState<'approve' | 'reject' | null>(null);
    const [importModalOpened, setImportModalOpened] = useState(false);
    const [importPreviewRows, setImportPreviewRows] = useState<ImportPreviewRow[]>([]);

    // Clear the selection whenever the filtered rows change (new search, page, or status
    // filter) so a stale selection from a previous view can't leak into a bulk action.
    useEffect(() => {
        setSelectedRecords([]);
    }, [data]);

    const closeDetail = () => {
        setDetailRequest(null);
        setConflicts(null);
    };

    const handleApprove = async (request: WorkHistoryEditRequest, force = false) => {
        try {
            await approveMutation.mutateAsync({ id: request.id, force });
            NotificationHelper.showSuccess(t('Successfully approved request'));
            closeDetail();
        } catch (e) {
            const approveConflicts = getApproveConflicts(e);
            if (approveConflicts) {
                setConflicts(approveConflicts);
                return;
            }
            NotificationHelper.showError(getApproveErrorMessage(t, e, t('error|Could not approve request')));
            if (isRequestNoLongerApprovable(e)) {
                queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
                closeDetail();
            }
        }
    };

    const handleReject = async (rejectReason: string) => {
        if (!detailRequest) return;
        try {
            await rejectMutation.mutateAsync({ id: detailRequest.id, reject_reason: rejectReason });
            NotificationHelper.showSuccess(t('Successfully rejected request'));
            setRejectModalOpened(false);
            closeDetail();
        } catch (e) {
            NotificationHelper.showError(t('error|Could not reject request'));
            throw e;
        }
    };

    const handleBulkApproveConfirm = async () => {
        try {
            await bulkMutation.mutateAsync({ ids: selectedRecords.map((r) => r.id), action: 'approve' });
            NotificationHelper.showSuccess(t('Successfully processed selected requests'));
            setSelectedRecords([]);
        } catch (e) {
            NotificationHelper.showError(
                getBulkResolveErrorMessage(t, e, t('error|Could not process selected requests')),
            );
            queryClient.invalidateQueries({ queryKey: EDIT_REQUESTS_QUERY_KEY });
        } finally {
            setBulkAction(null);
        }
    };

    const handleBulkReject = async (rejectReason: string) => {
        try {
            await bulkMutation.mutateAsync({
                ids: selectedRecords.map((r) => r.id),
                action: 'reject',
                reject_reason: rejectReason,
            });
            NotificationHelper.showSuccess(t('Successfully processed selected requests'));
            setSelectedRecords([]);
            setBulkAction(null);
        } catch (e) {
            NotificationHelper.showError(
                getBulkResolveErrorMessage(t, e, t('error|Could not process selected requests')),
            );
            throw e;
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Work history edit requests')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Work history edit requests')}
                </Title>
                {perms.WORK_HISTORY_EDIT_REQUEST_PROCESS && (
                    <Flex gap="xs">
                        {selectedRecords.length > 0 && (
                            <>
                                <Button color="teal" onClick={() => setBulkAction('approve')}>
                                    {t('Approve {{count}} requests', { count: selectedRecords.length })}
                                </Button>
                                <Button color="red" onClick={() => setBulkAction('reject')}>
                                    {t('Reject {{count}} requests', { count: selectedRecords.length })}
                                </Button>
                            </>
                        )}
                        <Button variant="outline" onClick={() => setImportModalOpened(true)}>
                            {t('Import from Excel')}
                        </Button>
                    </Flex>
                )}
            </Flex>

            <CommonSearchBox
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
                            ...EnumHelper.toSelectArray(WorkHistoryEditRequestStatusEnum),
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
                    tableKey="work-history-edit-request"
                    columns={[
                        {
                            name: t('Employee'),
                            key: 'employee',
                            render: (row: WorkHistoryEditRequest) =>
                                row.check_in ? <UserWithAvatar user={row.check_in.user} /> : '-',
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (row: WorkHistoryEditRequest) => row.workplace?.name || '-',
                        },
                        {
                            name: t('Date'),
                            key: 'date',
                            render: (row: WorkHistoryEditRequest) =>
                                row.check_in ? DateHelper.parseAndFormat(row.check_in.shift.date) : '-',
                        },
                        {
                            name: t('Changed fields'),
                            key: 'fields',
                            render: (row: WorkHistoryEditRequest) =>
                                (row.items || []).map((item) => fieldLabel(t, item.field)).join(', '),
                        },
                        {
                            name: t('Submitted at'),
                            key: 'created_at',
                            render: (row: WorkHistoryEditRequest) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            render: (row: WorkHistoryEditRequest) => (
                                <Badge
                                    size="xs"
                                    color={EnumHelper.getColor(WorkHistoryEditRequestStatusEnum, row.status)}
                                >
                                    {EnumHelper.getText(WorkHistoryEditRequestStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: WorkHistoryEditRequest) => (
                                <Anchor onClick={() => setDetailRequest(row)}>{t('Detail')}</Anchor>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    selectedRecords={selectedRecords}
                    onSelectedRecordsChange={setSelectedRecords}
                    isRecordSelectable={(row: WorkHistoryEditRequest) => row.status === 'pending'}
                    noPermission={!perms.WORK_HISTORY_EDIT_REQUEST_PROCESS}
                />
            </Paper>

            {
                // Hidden (not just visually covered) while the reject dialog is open — two
                // simultaneously-opened Mantine Modals stack ambiguously, leaving the one
                // underneath unclickable. detailRequest itself stays set so handleReject can
                // still read it, and this modal reappears once the reject dialog closes.
                !rejectModalOpened && (
                    <RequestDetailModal
                        request={detailRequest}
                        onClose={closeDetail}
                        onApprove={() => detailRequest && handleApprove(detailRequest)}
                        onApproveAnyway={() => detailRequest && handleApprove(detailRequest, true)}
                        onReject={() => setRejectModalOpened(true)}
                        canProcess={perms.WORK_HISTORY_EDIT_REQUEST_PROCESS}
                        isProcessing={approveMutation.isPending}
                        conflicts={conflicts}
                    />
                )
            }

            <RejectRequestModal
                opened={rejectModalOpened}
                onClose={() => setRejectModalOpened(false)}
                onSubmit={handleReject}
                isProcessing={rejectMutation.isPending}
            />

            <DConfirmModal
                opened={bulkAction === 'approve'}
                title={t('Approve selected requests')}
                message={t('All changed fields in the {{count}} selected requests will be applied immediately.', {
                    count: selectedRecords.length,
                })}
                onConfirm={handleBulkApproveConfirm}
                onCancel={() => setBulkAction(null)}
                confirmButtonLabel={t('Approve')}
                confirmButtonColor="teal"
                isProcessing={bulkMutation.isPending}
            />

            <BulkRejectModal
                opened={bulkAction === 'reject'}
                count={selectedRecords.length}
                onClose={() => setBulkAction(null)}
                onSubmit={handleBulkReject}
                isProcessing={bulkMutation.isPending}
            />

            {
                // Hidden (not just visually covered) while the review modal is open, same reason
                // as RequestDetailModal above: two simultaneously-opened Mantine Modals stack
                // ambiguously.
                importPreviewRows.length === 0 && (
                    <ImportModal
                        opened={importModalOpened}
                        onClose={() => setImportModalOpened(false)}
                        onPreviewReady={(rows) => {
                            setImportModalOpened(false);
                            setImportPreviewRows(rows);
                        }}
                    />
                )
            }

            <ImportReviewModal
                rows={importPreviewRows}
                onClose={() => setImportPreviewRows([])}
                onConfirmed={() => setImportPreviewRows([])}
            />
        </>
    );
};

export default IndexPage;
