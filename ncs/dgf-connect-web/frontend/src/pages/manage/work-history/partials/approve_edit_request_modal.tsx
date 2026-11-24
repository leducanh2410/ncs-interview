import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Alert, Box, Button, Flex, Modal, Text } from '@mantine/core';
import { useQueryClient } from '@tanstack/react-query';
import { CheckIn } from '@/types/models/check_in';
import { WorkHistoryEditRequestConflict } from '@/types/models/work_history_edit_request';
import NotificationHelper from '@/helpers/notification.helper';
import EditRequestConflicts from '@/pages/manage/work-history/edit-requests/partials/edit_request_conflicts';
import EditRequestItems from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';
import RejectRequestModal from '@/pages/manage/work-history/edit-requests/partials/reject_request_modal';
import {
    getApproveConflicts,
    getApproveErrorMessage,
    isRequestNoLongerApprovable,
} from '@/pages/manage/work-history/partials/approve_edit_request_error';
import { useApproveWorkHistoryEditRequestMutation } from '@/pages/manage/work-history/api';
import { useRejectWorkHistoryEditRequestMutation } from '@/pages/manage/work-history/edit-requests/api';

type Props = {
    // The row is expected to carry a `pending` latest_edit_request; the modal renders nothing
    // otherwise (e.g. while it is closing after a successful approve).
    checkIn: CheckIn | null;
    onClose: () => void;
};

const ApproveEditRequestModal = ({ checkIn, onClose }: Props) => {
    const { t } = useTranslation();
    const queryClient = useQueryClient();
    const approveMutation = useApproveWorkHistoryEditRequestMutation();
    const rejectMutation = useRejectWorkHistoryEditRequestMutation();
    const [rejectModalOpened, setRejectModalOpened] = useState(false);
    const [conflicts, setConflicts] = useState<WorkHistoryEditRequestConflict[] | null>(null);
    const request = checkIn?.latest_edit_request;

    if (!checkIn || !request) {
        return null;
    }

    const handleClose = () => {
        setConflicts(null);
        onClose();
    };

    const handleApprove = async (force: boolean) => {
        try {
            await approveMutation.mutateAsync({ id: request.id, force });
            NotificationHelper.showSuccess(t('Successfully approved request'));
            handleClose();
        } catch (e) {
            const approveConflicts = getApproveConflicts(e);
            if (approveConflicts) {
                setConflicts(approveConflicts);
                return;
            }
            NotificationHelper.showError(getApproveErrorMessage(t, e, t('error|Could not approve request')));
            if (isRequestNoLongerApprovable(e)) {
                queryClient.invalidateQueries({ queryKey: ['work-history'] });
                handleClose();
            }
        }
    };

    const handleReject = async (rejectReason: string) => {
        try {
            await rejectMutation.mutateAsync({ id: request.id, reject_reason: rejectReason });
            NotificationHelper.showSuccess(t('Successfully rejected request'));
            setRejectModalOpened(false);
            handleClose();
        } catch (e) {
            NotificationHelper.showError(t('error|Could not reject request'));
            throw e;
        }
    };

    return (
        <>
            {
                // Hidden (not just visually covered) while the reject dialog is open — two
                // simultaneously-opened Mantine Modals stack ambiguously, leaving the one
                // underneath unclickable.
                !rejectModalOpened && (
                    <Modal opened onClose={handleClose} title={t('Approve edit request')} size="lg">
                        <Text mb="md">
                            {t('Employee')}: <b>{checkIn.user.name}</b> — {t('Workplace')}:{' '}
                            <b>{checkIn.shift.workplace.name}</b>
                        </Text>
                        <Box mb="md">
                            <EditRequestItems items={request.items || []} />
                        </Box>
                        {conflicts ? (
                            <EditRequestConflicts conflicts={conflicts} />
                        ) : (
                            <Alert color="orange" mb="md">
                                {t('Approving will apply these changes to the check-in record immediately.')}
                            </Alert>
                        )}
                        <Flex justify="flex-end" gap="xs">
                            <Button color="gray" onClick={handleClose}>
                                {t('Cancel')}
                            </Button>
                            <Button color="red" variant="outline" onClick={() => setRejectModalOpened(true)}>
                                {t('Reject')}
                            </Button>
                            {conflicts ? (
                                <Button
                                    color="orange"
                                    onClick={() => handleApprove(true)}
                                    loading={approveMutation.isPending}
                                >
                                    {t('Apply requested values anyway')}
                                </Button>
                            ) : (
                                <Button
                                    color="teal"
                                    onClick={() => handleApprove(false)}
                                    loading={approveMutation.isPending}
                                >
                                    {t('Approve')}
                                </Button>
                            )}
                        </Flex>
                    </Modal>
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

export default ApproveEditRequestModal;
