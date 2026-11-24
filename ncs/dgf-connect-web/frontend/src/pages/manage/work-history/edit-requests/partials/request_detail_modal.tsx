import { useTranslation } from 'react-i18next';
import { Badge, Box, Button, Flex, Modal, Text } from '@mantine/core';
import { WorkHistoryEditRequest, WorkHistoryEditRequestConflict } from '@/types/models/work_history_edit_request';
import { WorkHistoryEditRequestStatusEnum } from '@/enums/work_history_edit_request_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import EditRequestConflicts from '@/pages/manage/work-history/edit-requests/partials/edit_request_conflicts';
import EditRequestItems from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';

const RequestDetailModal = ({
    request,
    onClose,
    onApprove,
    onApproveAnyway,
    onReject,
    canProcess,
    isProcessing,
    conflicts,
}: Props) => {
    const { t } = useTranslation();

    if (!request) return null;

    const isPending = request.status === 'pending';

    return (
        <Modal opened onClose={onClose} title={t('Edit request detail')} size="lg">
            <Flex justify="space-between" align="center" mb="md">
                <Text>
                    {t('Employee')}: <b>{request.check_in?.user.name}</b> — {t('Workplace')}:{' '}
                    <b>{request.workplace?.name}</b>
                </Text>
                <Badge color={EnumHelper.getColor(WorkHistoryEditRequestStatusEnum, request.status)}>
                    {EnumHelper.getText(WorkHistoryEditRequestStatusEnum, request.status)}
                </Badge>
            </Flex>

            <Box mb="md">
                <EditRequestItems items={request.items || []} />
            </Box>

            {!isPending && (
                <Box mb="md">
                    <Text fw={600} mb={4}>
                        {t('Decision')}
                    </Text>
                    <Text>
                        {t('Decided by')}: <b>{request.processed_by?.name || '-'}</b>
                    </Text>
                    <Text>
                        {t('Decided at')}:{' '}
                        <b>
                            {request.processed_at
                                ? DateHelper.parseAndFormat(request.processed_at, null, t('FORMAT_DATE_WITH_TIME'))
                                : '-'}
                        </b>
                    </Text>
                    {request.status === 'rejected' && (
                        <Text>
                            {t('Reject reason')}: <b>{request.reject_reason}</b>
                        </Text>
                    )}
                </Box>
            )}

            {isPending && conflicts && <EditRequestConflicts conflicts={conflicts} />}

            {isPending && canProcess && (
                <Flex justify="flex-end" gap="xs">
                    <Button color="red" variant="outline" onClick={onReject} loading={isProcessing}>
                        {t('Reject')}
                    </Button>
                    {conflicts && onApproveAnyway ? (
                        <Button color="orange" onClick={onApproveAnyway} loading={isProcessing}>
                            {t('Apply requested values anyway')}
                        </Button>
                    ) : (
                        <Button color="teal" onClick={onApprove} loading={isProcessing}>
                            {t('Approve')}
                        </Button>
                    )}
                </Flex>
            )}
        </Modal>
    );
};

type Props = {
    request: WorkHistoryEditRequest | null;
    onClose: () => void;
    onApprove: () => void;
    onApproveAnyway?: () => void;
    onReject: () => void;
    canProcess: boolean;
    isProcessing?: boolean;
    conflicts?: WorkHistoryEditRequestConflict[] | null;
};

export default RequestDetailModal;
