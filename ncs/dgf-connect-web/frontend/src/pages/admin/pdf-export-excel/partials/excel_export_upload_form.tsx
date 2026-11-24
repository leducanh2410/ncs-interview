import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuDownload, LuFileSpreadsheet, LuX } from 'react-icons/lu';
import { Alert, Button, Flex, Group, Paper, Select, Stack, Text } from '@mantine/core';
import { Dropzone, FileWithPath } from '@mantine/dropzone';

const SPREADSHEET_MIME_TYPES = {
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': ['.xlsx'],
    'application/vnd.ms-excel': ['.xls', '.csv'],
    'text/csv': ['.csv'],
    'text/plain': ['.csv'],
};

export type TemplateOption = { value: string; label: string; disabled?: boolean };

export const formatFileSizeMb = (sizeKb: number): string => {
    const sizeMb = sizeKb / 1024;

    return Number.isInteger(sizeMb) ? String(sizeMb) : sizeMb.toFixed(1);
};

type Props = {
    templateOptions: TemplateOption[];
    isTemplatesLoading: boolean;
    templateId: string | null;
    onTemplateChange: (value: string | null) => void;
    file: FileWithPath | null;
    onFileChange: (file: FileWithPath | null) => void;
    maxFileSizeKb: number;
    onSubmit: () => void;
    isSubmitting: boolean;
    onDownloadSample: () => void;
    isDownloadingSample: boolean;
    errorMessage: string | null;
};

const ExcelExportUploadForm = ({
    templateOptions,
    isTemplatesLoading,
    templateId,
    onTemplateChange,
    file,
    onFileChange,
    maxFileSizeKb,
    onSubmit,
    isSubmitting,
    onDownloadSample,
    isDownloadingSample,
    errorMessage,
}: Props) => {
    const { t } = useTranslation();

    return (
        <Paper p="md">
            <Stack gap="md">
                <Alert variant="light" color="blue" title={t('Input file format')}>
                    <Text size="sm">
                        {t(
                            'Use the first sheet. Column A must contain the staff number, column B may contain the name for reference only. A header row is optional; if you include one, use the column names from the sample file so it is not counted as a staff row.',
                        )}
                    </Text>
                    <Button
                        mt="xs"
                        size="xs"
                        variant="light"
                        leftSection={<LuDownload size={14} />}
                        onClick={onDownloadSample}
                        loading={isDownloadingSample}
                    >
                        {t('Download sample file')}
                    </Button>
                </Alert>

                <Select
                    label={t('Template')}
                    placeholder={t('Select a template')}
                    data={templateOptions}
                    value={templateId}
                    onChange={onTemplateChange}
                    disabled={isTemplatesLoading || isSubmitting}
                    searchable
                    required
                />

                <div>
                    <Text size="sm" fw={500} mb={4}>
                        {t('Excel file')} <span style={{ color: 'var(--mantine-color-red-6)' }}>*</span>
                    </Text>
                    <Dropzone
                        onDrop={(files) => onFileChange(files[0] ?? null)}
                        onReject={() => onFileChange(null)}
                        maxSize={maxFileSizeKb * 1024}
                        accept={SPREADSHEET_MIME_TYPES}
                        multiple={false}
                        disabled={isSubmitting}
                    >
                        <Group justify="center" gap="md" mih={100} style={{ pointerEvents: 'none' }}>
                            <Dropzone.Accept>
                                <LuCheck size={40} color="var(--mantine-color-blue-6)" />
                            </Dropzone.Accept>
                            <Dropzone.Reject>
                                <LuX size={40} color="var(--mantine-color-red-6)" />
                            </Dropzone.Reject>
                            <Dropzone.Idle>
                                <LuFileSpreadsheet size={40} color="var(--mantine-color-dimmed)" />
                            </Dropzone.Idle>
                            <div>
                                <Text size="md" inline>
                                    {t('Drag an Excel file here or click to select')}
                                </Text>
                                <Text size="sm" c="dimmed" inline mt={7}>
                                    {t('Accepted: .xlsx, .xls, .csv. Max file size: {{size}}MB', {
                                        size: formatFileSizeMb(maxFileSizeKb),
                                    })}
                                </Text>
                            </div>
                        </Group>
                    </Dropzone>
                    {file && (
                        <Text size="sm" mt="xs" c="blue">
                            {file.name} ({(file.size / 1024).toFixed(1)} KB)
                        </Text>
                    )}
                </div>

                {errorMessage && (
                    <Alert variant="light" color="red">
                        {errorMessage}
                    </Alert>
                )}

                <Flex justify="flex-end">
                    <Button onClick={onSubmit} disabled={!templateId || !file} loading={isSubmitting}>
                        {t('Check user list')}
                    </Button>
                </Flex>
            </Stack>
        </Paper>
    );
};

export default ExcelExportUploadForm;
