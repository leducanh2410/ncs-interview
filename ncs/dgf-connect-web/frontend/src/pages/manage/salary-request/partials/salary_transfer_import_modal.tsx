import React, { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuUpload } from 'react-icons/lu';
import { Box, Button, FileButton, Modal, Radio, Stack } from '@mantine/core';
import i18n from '@/locales/i18n';
import { EncodingEnum } from '@/enums/encoding.enum';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DAlert from '@/components/DAlert';
import { useImportSalaryTransferMutation } from '@/pages/manage/salary-request/api';

const getErrorMessage = (errorCode: string) => {
    switch (errorCode) {
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_INVALID_STAFF_NO.value:
            return i18n.t('error|Invalid staff number');
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_INVALID_AMOUNT.value:
            return i18n.t('error|Invalid amount');
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND.value:
            return i18n.t('error|Salary request not found');
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH.value:
            return i18n.t('error|Amount does not match');
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_INVALID_TIME.value:
            return i18n.t('error|Invalid time format');
        case ErrorCodeEnum.SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH.value:
            return i18n.t('error|Amount is too high');
        default:
            return i18n.t('error|Unknown error');
    }
};

const SalaryTransferImportModal = ({ opened, onClose }: Props) => {
    const { t } = useTranslation();
    const fileInputRef = useRef<() => void>(null);
    const importTransfer = useImportSalaryTransferMutation();
    const [importErrors, setImportErrors] = useState<string[]>([]);
    const [encoding, setEncoding] = useState(EncodingEnum.SHIFT_JIS.value);

    const onFileSelected = async (file: File | null) => {
        setImportErrors([]);
        if (!file) {
            return;
        }

        try {
            const data = new FormData();
            data.append('_method', 'POST');
            data.append('encoding', encoding);
            data.append('file', file);
            await importTransfer.mutateAsync(data);
            NotificationHelper.showSuccess(t('Successfully imported CSV file'));
            setImportErrors([]);
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
                <FileButton resetRef={fileInputRef} onChange={onFileSelected} accept=".csv">
                    {(props) => (
                        <Button {...props} color="yellow" leftSection={<LuUpload />} loading={importTransfer.isPending}>
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
            </Stack>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    onClose: () => void;
};
export default SalaryTransferImportModal;
