import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCircleCheck } from 'react-icons/lu';
import Link from 'next/link';
import { Alert, Anchor, Box, Breadcrumbs, Button, Group, Paper, Stepper, Text, Title } from '@mantine/core';
import { FileWithPath } from '@mantine/dropzone';
import { ExcelExportPreview as ExcelExportPreviewData } from '@/types/models/pdf_export_excel';
import { ExportBatch } from '@/types/models/pdf_template';
import RouteConfig from '@/configs/route.config';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import ExcelExportPreview from './partials/excel_export_preview';
import ExcelExportUploadForm, { TemplateOption, formatFileSizeMb } from './partials/excel_export_upload_form';
import { usePermissions } from '@/hooks/usePermission';
import {
    ExcelExportPayload,
    useCreateExcelBatchExportMutation,
    useDownloadExcelSampleMutation,
    useGetExcelExportLimitsQuery,
    usePreviewExcelExportMutation,
} from '@/pages/admin/pdf-export-excel/api';
import { useGetPdfTemplatesQuery } from '@/pages/admin/pdf-template/api';

const STEP_UPLOAD = 0;
const STEP_PREVIEW = 1;
const STEP_DONE = 2;
const DEFAULT_MAX_FILE_SIZE_KB = 5120;

const PdfExportExcelPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'PDF_TEMPLATE_EXPORT']);

    const [templateId, setTemplateId] = useState<string | null>(null);
    const [file, setFile] = useState<FileWithPath | null>(null);
    const [preview, setPreview] = useState<ExcelExportPreviewData | null>(null);
    const [previewRequest, setPreviewRequest] = useState<ExcelExportPayload | null>(null);
    const [createdBatch, setCreatedBatch] = useState<ExportBatch | null>(null);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);

    const { data: templatesResponse, isPending: isTemplatesPending } = useGetPdfTemplatesQuery(
        { page_size: '100' },
        perms.PDF_TEMPLATE_LIST,
    );
    const { data: limitsResponse } = useGetExcelExportLimitsQuery(perms.PDF_TEMPLATE_EXPORT);
    const previewMutation = usePreviewExcelExportMutation();
    const createMutation = useCreateExcelBatchExportMutation();
    const sampleMutation = useDownloadExcelSampleMutation();

    const templateOptions = useMemo<TemplateOption[]>(() => {
        return (templatesResponse?.data?.data ?? []).map((template) => ({
            value: template.id.toString(),
            label: template.lock_info ? `${template.name} (${t('Locked')})` : template.name,
            disabled: !!template.lock_info,
        }));
    }, [templatesResponse, t]);

    const maxFileSizeKb = limitsResponse?.data?.max_file_size_kb ?? DEFAULT_MAX_FILE_SIZE_KB;
    const invalidFileMessage = t('Invalid file. Please upload an Excel file under {{size}}MB.', {
        size: formatFileSizeMb(maxFileSizeKb),
    });

    const activeStep = createdBatch ? STEP_DONE : preview ? STEP_PREVIEW : STEP_UPLOAD;

    const describeError = (error: unknown): string => {
        if (error instanceof ApiError) {
            switch (error.message) {
                case 'PDF_EXPORT_EXCEL_INVALID_FORMAT':
                    return t('The file could not be read. Please upload a valid Excel file.');
                case 'PDF_EXPORT_EXCEL_EMPTY':
                    return t('The file has no data rows.');
                case 'PDF_EXPORT_EXCEL_TOO_MANY_ROWS':
                    return t('The file has too many rows. Maximum is {{count}} rows.', {
                        count: error.data?.max_rows ?? '-',
                    });
                case 'PDF_EXPORT_EXCEL_NO_USERS_MATCHED':
                    return t('No user in the file matches a staff number in the system. Nothing can be exported.');
                case 'PDF_TEMPLATE_LOCKED':
                    return t('Template is currently locked. Please try again later.');
                case 'E_UNPROCESSABLE_ENTITY':
                    return invalidFileMessage;
                default:
                    return t('Failed to process the file');
            }
        }

        return t('Failed to process the file');
    };

    const handlePreview = async () => {
        if (!templateId || !file) return;
        setErrorMessage(null);
        const request: ExcelExportPayload = { templateId, file };

        try {
            const response = await previewMutation.mutateAsync(request);
            if (response.data) {
                setPreviewRequest(request);
                setPreview(response.data);
            }
        } catch (error) {
            setErrorMessage(describeError(error));
        }
    };

    const handleConfirm = async () => {
        if (!previewRequest) return;

        try {
            const response = await createMutation.mutateAsync(previewRequest);
            if (response.data) {
                setCreatedBatch(response.data);
                NotificationHelper.showSuccess(t('Export started successfully'));
            }
        } catch (error) {
            NotificationHelper.showError(describeError(error));
        }
    };

    const handleDownloadSample = async () => {
        try {
            await sampleMutation.mutateAsync();
        } catch {
            NotificationHelper.showError(t('Could not download sample file'));
        }
    };

    const handleBack = () => {
        setPreview(null);
        setPreviewRequest(null);
        setErrorMessage(null);
    };

    const handleReset = () => {
        setTemplateId(null);
        setFile(null);
        setPreview(null);
        setPreviewRequest(null);
        setCreatedBatch(null);
        setErrorMessage(null);
    };

    return (
        <Box>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Export PDF from Excel')}</Text>
            </Breadcrumbs>

            <Group justify="space-between" my="md">
                <Title order={4} lh="48px">
                    {t('Export PDF from Excel')}
                </Title>
                <Anchor component={Link} href={`${RouteConfig.EXPORT_HISTORY.path}?type=pdf&source=excel`} size="sm">
                    {t('View export history')}
                </Anchor>
            </Group>

            <Stepper active={activeStep} mb="md">
                <Stepper.Step label={t('Upload')} description={t('Choose template and file')} />
                <Stepper.Step label={t('Review')} description={t('Check matched users')} />
                <Stepper.Step label={t('Export')} description={t('PDF generation started')} />
            </Stepper>

            {!perms.PDF_TEMPLATE_LIST && (
                <Alert variant="light" color="yellow" mb="md">
                    {t('You need the PDF template list permission to choose a template.')}
                </Alert>
            )}

            {activeStep === STEP_UPLOAD && (
                <ExcelExportUploadForm
                    templateOptions={templateOptions}
                    isTemplatesLoading={isTemplatesPending}
                    templateId={templateId}
                    onTemplateChange={setTemplateId}
                    file={file}
                    onFileChange={(nextFile) => {
                        setFile(nextFile);
                        setErrorMessage(nextFile ? null : invalidFileMessage);
                    }}
                    maxFileSizeKb={maxFileSizeKb}
                    onSubmit={handlePreview}
                    isSubmitting={previewMutation.isPending}
                    onDownloadSample={handleDownloadSample}
                    isDownloadingSample={sampleMutation.isPending}
                    errorMessage={errorMessage}
                />
            )}

            {activeStep === STEP_PREVIEW && preview && (
                <ExcelExportPreview
                    preview={preview}
                    onBack={handleBack}
                    onConfirm={handleConfirm}
                    isConfirming={createMutation.isPending}
                />
            )}

            {activeStep === STEP_DONE && createdBatch && (
                <Paper p="md">
                    <Alert variant="light" color="green" icon={<LuCircleCheck size={18} />} title={t('Export started')}>
                        <Text size="sm">
                            {t('{{count}} PDF files are being generated in the background.', {
                                count: createdBatch.total_count,
                            })}
                        </Text>
                    </Alert>
                    <Group mt="md">
                        <Button
                            component={Link}
                            href={RouteHelper.getPath('EXPORT_BATCH_DETAIL', { id: createdBatch.id })}
                        >
                            {t('View progress')}
                        </Button>
                        <Button variant="default" onClick={handleReset}>
                            {t('Export another file')}
                        </Button>
                    </Group>
                </Paper>
            )}
        </Box>
    );
};

export default PdfExportExcelPage;
