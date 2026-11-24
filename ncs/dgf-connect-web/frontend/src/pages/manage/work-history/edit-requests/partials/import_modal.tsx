import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuUpload } from 'react-icons/lu';
import { Box, Button, FileButton, List, Modal, Stack, Text } from '@mantine/core';
import i18n from '@/locales/i18n';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DAlert from '@/components/DAlert';
import {
    ImportPreviewRow,
    usePreviewImportWorkHistoryEditRequestMutation,
} from '@/pages/manage/work-history/edit-requests/api';

type RowError = { row: number; employee_name: string; date: string; reason: string };

const getStructuralErrorMessage = (data: {
    reason?: string;
    column?: string;
    expected?: string;
    found?: string | null;
}) => {
    switch (data.reason) {
        case 'empty_file':
            return i18n.t('error|The file is empty');
        case 'no_data_rows':
            return i18n.t('error|The file has no data rows to import');
        case 'header_mismatch':
            return i18n.t(
                'error|Column {{column}} should be "{{expected}}" but found "{{found}}" - make sure this is the unmodified exported file',
                {
                    column: data.column,
                    expected: data.expected,
                    found: data.found ?? i18n.t('(empty)'),
                },
            );
        default:
            return i18n.t('error|The file could not be read as a valid exported .xlsx file');
    }
};

const getRowErrorMessage = (reason: string) => {
    switch (reason) {
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_MISSING.value:
            return i18n.t('error|Missing or invalid row identifier');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE.value:
            return i18n.t('error|Duplicate row identifier');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND.value:
            return i18n.t('error|No matching check-in record was found for this row');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING.value:
            return i18n.t('error|This row already has another request pending');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE.value:
            return i18n.t('error|This row belongs to a different workplace than the rest of the file');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE.value:
            return i18n.t('error|One of the values in this row is not a valid number');
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN.value:
            return i18n.t(
                'error|The end time would fall before the start time for this row - please fix both the "Giờ vào làm" and "Giờ tan làm" columns',
            );
        case ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION.value:
            return i18n.t(
                'error|The gap between the start and end time is implausibly long for this row - please check both the "Giờ vào làm" and "Giờ tan làm" columns',
            );
        default:
            return i18n.t('error|Unknown error');
    }
};

const ImportModal = ({ opened, onClose, onPreviewReady }: Props) => {
    const { t } = useTranslation();
    const fileInputRef = useRef<() => void>(null);
    const previewMutation = usePreviewImportWorkHistoryEditRequestMutation();
    const [formatError, setFormatError] = useState<string | null>(null);
    const [rowErrors, setRowErrors] = useState<RowError[]>([]);
    const [noChanges, setNoChanges] = useState(false);

    const resetMessages = () => {
        setFormatError(null);
        setRowErrors([]);
        setNoChanges(false);
    };

    const closeModal = () => {
        resetMessages();
        fileInputRef.current?.();
        onClose();
    };

    const onFileSelected = async (file: File | null) => {
        resetMessages();
        if (!file) return;

        try {
            const response = await previewMutation.mutateAsync(file);
            fileInputRef.current?.();
            onPreviewReady(response.data?.rows ?? []);
        } catch (e) {
            fileInputRef.current?.();
            if (!(e instanceof ApiError)) {
                return;
            }
            if (e.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES.value) {
                setNoChanges(true);
            } else if (e.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR.value) {
                setRowErrors((e.data.errors as RowError[]) ?? []);
            } else if (e.message === ErrorCodeEnum.WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT.value) {
                setFormatError(getStructuralErrorMessage(e.data as { reason?: string }));
            } else if (e.message === ErrorCodeEnum.E_FORBIDDEN.value) {
                setFormatError(t('error|You do not have permission to import this file for its workplace'));
            } else {
                setFormatError(t('error|Could not read the uploaded file'));
            }
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Import edit requests from Excel')} size="lg">
            <Stack gap="sm">
                <Text size="sm" c="dimmed">
                    {t(
                        'Upload the detail export file from the Work History screen, with the attendance values you edited by hand. Nothing will be changed until you review and confirm.',
                    )}
                </Text>
                <Box data-testid="import-scope-notice">
                    <Text size="sm">
                        {t(
                            'Only the following columns are read from the file - the same fields you can edit on the Work History screen:',
                        )}
                    </Text>
                    {/* These are the literal Vietnamese column headers from the exported .xlsx
                        file (App\Exports\WorkHistoryDetailExport::headings), not UI copy - the
                        file's headers never change with the viewer's locale, so translating them
                        here would show a label that no longer matches the actual column. */}
                    <List size="sm" icon={<Text span>-</Text>} withPadding>
                        <List.Item>Giờ vào làm</List.Item>
                        <List.Item>Giờ tan làm</List.Item>
                        <List.Item>Giờ nghỉ</List.Item>
                    </List>
                    <Text size="sm" c="dimmed">
                        {t(
                            'Other columns (e.g. "Giờ vào làm (tính lương)", "Tổng giờ làm") are calculated automatically and are ignored even if you change them.',
                        )}
                    </Text>
                </Box>
                <FileButton resetRef={fileInputRef} onChange={onFileSelected} accept=".xlsx">
                    {(props) => (
                        <Button
                            {...props}
                            color="yellow"
                            leftSection={<LuUpload />}
                            loading={previewMutation.isPending}
                        >
                            {t('Select file')}
                        </Button>
                    )}
                </FileButton>

                {noChanges && (
                    <DAlert type="info">{t('No changes were detected between the file and the current data')}</DAlert>
                )}

                {formatError && <DAlert type="danger">{formatError}</DAlert>}

                {rowErrors.length > 0 && (
                    <DAlert type="danger">
                        <Text fw={600} mb={4}>
                            {t('The following rows could not be reconciled - fix them and re-upload the file')}
                        </Text>
                        {rowErrors.map((error, index) => (
                            <Box key={index}>
                                {t('Row {{row}} ({{employee}}, {{date}}): {{message}}', {
                                    row: error.row,
                                    employee: error.employee_name,
                                    date: error.date,
                                    message: getRowErrorMessage(error.reason),
                                })}
                            </Box>
                        ))}
                    </DAlert>
                )}
            </Stack>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    onClose: () => void;
    onPreviewReady: (rows: ImportPreviewRow[]) => void;
};

export default ImportModal;
