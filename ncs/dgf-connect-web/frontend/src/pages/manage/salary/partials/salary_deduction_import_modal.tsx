import React, { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuUpload } from 'react-icons/lu';
import { Box, Button, FileButton, Modal, Radio, Stack } from '@mantine/core';
import { MonthPickerInput } from '@mantine/dates';
import i18n from '@/locales/i18n';
import { EncodingEnum } from '@/enums/encoding.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT_MONTH } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DAlert from '@/components/DAlert';
import { useImportSalaryDeductionMutation } from '@/pages/manage/salary-request/api';

const getErrorMessage = (errorCode: string) => {
    switch (errorCode) {
        case ErrorCodeEnum.SALARY_CSV_INVALID_STAFF_NO.value:
            return i18n.t('error|Invalid staff number');
        case ErrorCodeEnum.SALARY_CSV_INVALID_AMOUNT.value:
            return i18n.t('error|Invalid amount');
        case ErrorCodeEnum.SALARY_CSV_AMOUNT_TOO_HIGH.value:
            return i18n.t('error|Amount is too high');
        case ErrorCodeEnum.SALARY_CSV_SALARY_NOT_FOUND.value:
            return i18n.t('error|Salary for this month has not been calculated yet');
        default:
            return i18n.t('error|Unknown error');
    }
};

const SalaryDeductionImportModal = ({ opened, onClose }: Props) => {
    const { t } = useTranslation();
    const [month, setMonth] = useState<Date | null>(null);
    useEffect(() => {
        setMonth(DateHelper.now().subtract(1, 'month').toDate());
    }, []);
    const fileInputRef = useRef<() => void>(null);
    const [encoding, setEncoding] = useState(EncodingEnum.SHIFT_JIS.value);
    const importDeduction = useImportSalaryDeductionMutation();
    const [importErrors, setImportErrors] = useState<string[]>([]);
    const [skippedLines, setSkippedLines] = useState<string[]>([]);

    const onFileSelected = async (file: File | null) => {
        setImportErrors([]);
        setSkippedLines([]);
        if (!file) {
            return;
        }

        try {
            const data = new FormData();
            data.append('_method', 'POST');
            data.append('encoding', encoding);
            data.append('file', file);
            data.append('month', month ? DateHelper.format(month, DATE_STORE_FORMAT_MONTH) : '');
            const result = await importDeduction.mutateAsync(data);
            setImportErrors([]);

            const appliedCount = Object.keys(result.data?.valid_data ?? {}).length;
            if (appliedCount > 0) {
                NotificationHelper.showSuccess(t('Successfully imported CSV file'));
            } else {
                NotificationHelper.showInfo(t('No deduction was applied from this CSV file'));
            }

            // Rows the import left alone because this system has no salary for that month yet.
            // The file did go through, so the modal stays open only to show which ones.
            const skipped = result.data?.skipped_data ?? [];
            if (skipped.length) {
                setSkippedLines(
                    skipped.map((row) =>
                        t('Line {{line}} ({{staffNo}}): salary for this month has not been calculated yet', {
                            line: row.line,
                            staffNo: row.staff_no ?? '-',
                        }),
                    ),
                );

                return;
            }

            onCloseModal();
        } catch (e) {
            // Anything that is not an ApiError (a dropped connection, say) still has to be
            // reported: silence here reads as success and invites a second upload.
            let message = t('error|Could not import CSV file');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.SALARY_CSV_MALFORMED.value) {
                    message = t('error|CSV file is malformed');
                }
                if (e.message === ErrorCodeEnum.E_UNPROCESSABLE_ENTITY.value) {
                    const { error_data: errorData } = e.data as { error_data: { line: number; error_code: string }[] };
                    if (errorData.length) {
                        setImportErrors(
                            errorData.map((error) =>
                                t('Line {{line}}: {{message}}', {
                                    line: error.line,
                                    message: getErrorMessage(error.error_code),
                                }),
                            ),
                        );
                    }
                }
            }

            NotificationHelper.showError(message);
        } finally {
            fileInputRef.current?.();
        }
    };

    const onCloseModal = () => {
        setImportErrors([]);
        setSkippedLines([]);
        fileInputRef.current?.();
        onClose();
    };

    return (
        <Modal opened={opened} onClose={onCloseModal} title={t('Import CSV')}>
            <Stack gap="sm">
                <Radio.Group label={t('Encoding')} value={encoding} onChange={(value) => setEncoding(value as string)}>
                    {EnumHelper.toSelectArray(EncodingEnum).map((item) => (
                        <Radio key={item.value} value={item.value} label={item.label} mb={4} />
                    ))}
                </Radio.Group>
                <MonthPickerInput
                    label={t('Month')}
                    valueFormat={t('FORMAT_MONTH')}
                    rightSection={<LuCalendar size={16} />}
                    value={month}
                    onChange={(value) => {
                        if (value) {
                            setMonth(
                                typeof value === 'string' ? (DateHelper.parse(value, null)?.toDate() ?? null) : value,
                            );
                        }
                    }}
                />
                <FileButton resetRef={fileInputRef} onChange={onFileSelected} accept=".csv">
                    {(props) => (
                        <Button
                            {...props}
                            color="yellow"
                            leftSection={<LuUpload />}
                            loading={importDeduction.isPending}
                        >
                            {t('Import CSV')}
                        </Button>
                    )}
                </FileButton>
                {importErrors.length > 0 && (
                    <DAlert type="danger">
                        {importErrors.map((errorItem) => (
                            <Box key={errorItem}>
                                <strong>{errorItem}</strong>
                            </Box>
                        ))}
                    </DAlert>
                )}
                {skippedLines.length > 0 && (
                    <DAlert type="warning">
                        {skippedLines.map((skippedItem) => (
                            <Box key={skippedItem}>{skippedItem}</Box>
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
};
export default SalaryDeductionImportModal;
