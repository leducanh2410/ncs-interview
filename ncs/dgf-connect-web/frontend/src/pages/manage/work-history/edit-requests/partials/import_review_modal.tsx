import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowRight, LuSave } from 'react-icons/lu';
import { Anchor, Button, Flex, Modal, Table, Text, Textarea } from '@mantine/core';
import { TFunction } from 'i18next';
import { WorkHistoryEditRequestField } from '@/types/models/work_history_edit_request';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import DAvatar from '@/components/DAvatar';
import { fieldLabel } from '@/pages/manage/work-history/edit-requests/partials/edit_request_items';
import {
    ImportCommitRow,
    ImportPreviewRow,
    useCommitImportWorkHistoryEditRequestMutation,
} from '@/pages/manage/work-history/edit-requests/api';

type Decision = { decision: 'apply' | 'reject'; reject_reason: string };

const formatValue = (t: TFunction, field: WorkHistoryEditRequestField, value: string | null): string => {
    if (value === null) return '-';
    if (field === 'break_minutes') return value;
    return DateHelper.parseAndFormat(value, DATE_STORE_FORMAT_WITH_TIME, t('FORMAT_DATE_WITH_TIME'));
};

// CommitBulkImportWorkHistoryEditRequestsAction attaches employee_name/date to every aborting
// row's error - not only a genuine data-changed conflict, but also a permission failure, a
// deleted check-in, or an already-pending request from elsewhere. A 404 (the check-in was
// deleted) only has check_in_id, so we look that id back up in the preview rows the modal
// already has. Branching on e.message (rather than just on whether that identity is present)
// picks a message that actually matches what happened, instead of telling the admin their
// data "may have changed" - and to re-import - when re-importing would never help (a 403)
// or the row is simply gone (a 404).
const getCommitErrorMessage = (t: TFunction, e: unknown, rows: ImportPreviewRow[]): string => {
    if (!(e instanceof ApiError)) {
        return t('error|Could not process the import - some data may have changed, please re-import');
    }

    const payload = (e.data ?? {}) as { employee_name?: string; date?: string; check_in_id?: number };
    const fallbackRow =
        payload.check_in_id !== undefined ? rows.find((row) => row.check_in_id === payload.check_in_id) : undefined;
    const employeeName = payload.employee_name ?? fallbackRow?.employee_name;
    const date = payload.date ?? fallbackRow?.date;
    const hasRow = Boolean(employeeName && date);

    switch (e.message) {
        case ErrorCodeEnum.E_FORBIDDEN.value:
            return hasRow
                ? t('error|You do not have permission to process the row for {{employee}} on {{date}}', {
                      employee: employeeName,
                      date,
                  })
                : t('error|You do not have permission to process this import');
        case ErrorCodeEnum.E_NOT_FOUND.value:
            return hasRow
                ? t('error|The row for {{employee}} on {{date}} no longer exists', {
                      employee: employeeName,
                      date,
                  })
                : t('error|One of the rows in this import no longer exists');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING.value:
            return hasRow
                ? t('error|The row for {{employee}} on {{date}} already has another request pending', {
                      employee: employeeName,
                      date,
                  })
                : t('error|One of the rows in this import already has another request pending');
        // Both cover the same underlying situation - the check-in (or its request) moved since
        // preview was computed - so they share one message; DATA_CHANGED is listed explicitly
        // (rather than left to fall into default) so it's clear this was a deliberate choice, not
        // an oversight, should a case ever need to be split out again.
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED.value:
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_NOT_PENDING.value:
        default:
            return hasRow
                ? t(
                      'error|Could not process the row for {{employee}} on {{date}} - its data may have changed since you previewed the file, please re-import',
                      { employee: employeeName, date },
                  )
                : t('error|Could not process the import - some data may have changed, please re-import');
    }
};

const ImportReviewModal = ({ rows, onClose, onConfirmed }: Props) => {
    const { t } = useTranslation();
    const commitMutation = useCommitImportWorkHistoryEditRequestMutation();
    const [decisions, setDecisions] = useState<Record<number, Decision>>({});

    useEffect(() => {
        setDecisions(
            Object.fromEntries(rows.map((row) => [row.check_in_id, { decision: 'apply', reject_reason: '' }])),
        );
    }, [rows]);

    if (rows.length === 0) return null;

    const setDecision = (checkInId: number, next: 'apply' | 'reject') => {
        setDecisions((prev) => ({
            ...prev,
            [checkInId]: { decision: next, reject_reason: prev[checkInId]?.reject_reason ?? '' },
        }));
    };

    const setRejectReason = (checkInId: number, reason: string) => {
        setDecisions((prev) => ({ ...prev, [checkInId]: { decision: 'reject', reject_reason: reason } }));
    };

    const rejectedCount = Object.values(decisions).filter((d) => d.decision === 'reject').length;
    const canConfirm = Object.values(decisions).every((d) => d.decision === 'apply' || d.reject_reason.trim() !== '');

    const closeModal = () => {
        setDecisions({});
        onClose();
    };

    const handleConfirm = async () => {
        const payload: ImportCommitRow[] = rows.map((row) => ({
            check_in_id: row.check_in_id,
            items: row.items,
            decision: decisions[row.check_in_id]?.decision ?? 'apply',
            reject_reason:
                decisions[row.check_in_id]?.decision === 'reject'
                    ? decisions[row.check_in_id].reject_reason
                    : undefined,
        }));

        try {
            const response = await commitMutation.mutateAsync(payload);
            NotificationHelper.showSuccess(
                t('Applied {{applied}} requests, rejected {{rejected}}', {
                    applied: response.data?.applied_count ?? 0,
                    rejected: response.data?.rejected_count ?? 0,
                }),
            );
            setDecisions({});
            onConfirmed();
        } catch (e) {
            NotificationHelper.showError(getCommitErrorMessage(t, e, rows));
        }
    };

    return (
        <Modal opened onClose={closeModal} title={t('Review detected changes')} size="1300px">
            <Text size="sm" c="dimmed" mb="sm">
                {t(
                    'Every row below will be applied unless you mark it "Reject" with a reason. Nothing is applied until you confirm.',
                )}
            </Text>
            <Table>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{t('Employee')}</Table.Th>
                        <Table.Th>{t('Workplace')}</Table.Th>
                        <Table.Th>{t('Date')}</Table.Th>
                        <Table.Th>{t('Changes')}</Table.Th>
                        <Table.Th miw={220}>{t('Decision')}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {rows.map((row) => (
                        <ReviewRow
                            key={row.check_in_id}
                            row={row}
                            decision={decisions[row.check_in_id] ?? { decision: 'apply', reject_reason: '' }}
                            onDecisionChange={(next) => setDecision(row.check_in_id, next)}
                            onReasonChange={(reason) => setRejectReason(row.check_in_id, reason)}
                        />
                    ))}
                </Table.Tbody>
            </Table>

            <Flex justify="space-between" align="center" mt="md">
                <Text size="sm" c="dimmed">
                    {t('{{count}} to apply, {{rejected}} to reject', {
                        count: rows.length - rejectedCount,
                        rejected: rejectedCount,
                    })}
                </Text>
                <Flex gap="xs">
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        color="teal"
                        leftSection={<LuSave />}
                        disabled={!canConfirm}
                        loading={commitMutation.isPending}
                        onClick={handleConfirm}
                    >
                        {t('Confirm and process')}
                    </Button>
                </Flex>
            </Flex>
        </Modal>
    );
};

const ReviewRow = ({
    row,
    decision,
    onDecisionChange,
    onReasonChange,
}: {
    row: ImportPreviewRow;
    decision: Decision;
    onDecisionChange: (next: 'apply' | 'reject') => void;
    onReasonChange: (reason: string) => void;
}) => {
    const { t } = useTranslation();
    const isRejected = decision.decision === 'reject';

    return (
        <Table.Tr>
            <Table.Td>
                <Flex align="center" gap={6} wrap="nowrap">
                    <DAvatar size="sm" name={row.employee_name} src={row.employee_avatar} />
                    <Anchor
                        component="a"
                        href={RouteHelper.getPath('ADMIN_USER_DETAIL', { id: row.user_id })}
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        {row.employee_name}
                    </Anchor>
                </Flex>
            </Table.Td>
            <Table.Td>
                <Anchor
                    component="a"
                    href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: row.workplace_id })}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {row.workplace_name}
                </Anchor>
            </Table.Td>
            <Table.Td>{row.date}</Table.Td>
            <Table.Td>
                {row.items.map((item) => (
                    <Flex key={item.field} align="center" gap={6} wrap="wrap">
                        <Text size="sm">{fieldLabel(t, item.field)}:</Text>
                        <Text size="sm" c="dimmed">
                            {formatValue(t, item.field, item.old_value_display ?? item.old_value)}
                        </Text>
                        <LuArrowRight size={14} />
                        <Text size="sm" fw={600}>
                            {formatValue(t, item.field, item.new_value)}
                        </Text>
                    </Flex>
                ))}
            </Table.Td>
            <Table.Td miw={200}>
                <Flex gap="xs" wrap="nowrap">
                    <Button
                        size="xs"
                        variant={isRejected ? 'filled' : 'outline'}
                        color="red"
                        onClick={() => onDecisionChange('reject')}
                    >
                        {t('Reject')}
                    </Button>
                    <Button
                        size="xs"
                        variant={isRejected ? 'outline' : 'filled'}
                        color="teal"
                        onClick={() => onDecisionChange('apply')}
                    >
                        {t('Apply')}
                    </Button>
                </Flex>
                {isRejected && (
                    <Textarea
                        mt={4}
                        size="xs"
                        placeholder={t('Reject reason')}
                        value={decision.reject_reason}
                        onChange={(e) => onReasonChange(e.currentTarget.value)}
                        error={decision.reject_reason.trim() === '' ? t('error|Reason is required') : undefined}
                    />
                )}
            </Table.Td>
        </Table.Tr>
    );
};

type Props = {
    rows: ImportPreviewRow[];
    onClose: () => void;
    onConfirmed: () => void;
};

export default ImportReviewModal;
