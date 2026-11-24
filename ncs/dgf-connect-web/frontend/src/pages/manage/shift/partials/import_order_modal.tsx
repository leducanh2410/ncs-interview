import React, { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuUpload } from 'react-icons/lu';
import { useRouter } from 'next/router';
import { Alert, Button, FileButton, List, Modal, Stack, Text } from '@mantine/core';
import { MonthPickerInput } from '@mantine/dates';
import dayjs from 'dayjs';
import { ShiftOrderImportError } from '@/types/models/import_batch';
import RouteHelper from '@/helpers/route.helper';
import {
    getShiftOrderImportErrorMessage,
    toShiftOrderImportErrors,
} from '@/pages/manage/shift/import-order/error_message';
import { useCreateShiftOrderPreviewMutation } from '@/pages/manage/shift/import-order/api';

const MONTH_STORE_FORMAT = 'YYYY-MM';

type Props = {
    isShow: boolean;
    onClose: () => void;
};

const ImportOrderModal = ({ isShow, onClose }: Props) => {
    const { t } = useTranslation();
    const router = useRouter();
    const resetFileRef = useRef<() => void>(() => {});
    const [month, setMonth] = useState<string | null>(null);
    const [errors, setErrors] = useState<ShiftOrderImportError[]>([]);
    const previewMutation = useCreateShiftOrderPreviewMutation();

    const handleClose = () => {
        setErrors([]);
        setMonth(null);
        resetFileRef.current?.();
        onClose();
    };

    const handleUpload = async (file: File | null) => {
        if (!file) {
            return;
        }

        setErrors([]);
        const formData = new FormData();
        formData.append('file', file);
        if (month) {
            formData.append('month', dayjs(month).format(MONTH_STORE_FORMAT));
        }

        try {
            const response = await previewMutation.mutateAsync(formData);
            const batchId = response.data?.batch.id;
            if (batchId) {
                handleClose();
                await router.push(RouteHelper.getPath('MANAGE_SHIFT_ORDER_IMPORT', { id: batchId }));
            }
        } catch (error) {
            setErrors(toShiftOrderImportErrors(error));
        } finally {
            resetFileRef.current?.();
        }
    };

    return (
        <Modal opened={isShow} onClose={handleClose} title={t('Import shift order')} size="lg">
            <Stack gap="sm">
                <Text size="sm" c="dimmed">
                    {t('Upload the monthly shift order workbook (.xlsx). Every workplace block in it is imported.')}
                </Text>
                <MonthPickerInput
                    label={t('Month')}
                    description={t('Leave empty to use the month written in the file')}
                    inputWrapperOrder={['label', 'input', 'description', 'error']}
                    valueFormat={t('FORMAT_MONTH')}
                    value={month}
                    onChange={setMonth}
                    clearable
                />
                {errors.length > 0 && (
                    <Alert color="red" title={t('The file cannot be imported')}>
                        <List size="sm" spacing={4}>
                            {errors.map((error, index) => (
                                <List.Item key={`${error.code}-${error.cell ?? index}`}>
                                    {getShiftOrderImportErrorMessage(error, t)}
                                </List.Item>
                            ))}
                        </List>
                    </Alert>
                )}
                <FileButton resetRef={resetFileRef} onChange={handleUpload} accept=".xlsx">
                    {(props) => (
                        <Button {...props} leftSection={<LuUpload size={14} />} loading={previewMutation.isPending}>
                            {t('Select file and check')}
                        </Button>
                    )}
                </FileButton>
            </Stack>
        </Modal>
    );
};

export default ImportOrderModal;
