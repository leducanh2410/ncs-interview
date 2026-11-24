import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Box, Breadcrumbs, Center, Loader, Text } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import { ExportBatchTypeEnum } from '@/enums/export_batch_type.enum';
import CsvBatchDetail from './partials/csv_batch_detail';
import PdfBatchDetail from './partials/pdf_batch_detail';
import WorkHistoryBatchDetail from './partials/work_history_batch_detail';
import { usePermissions } from '@/hooks/usePermission';
import { useGetUserExportBatchQuery } from '@/pages/admin/pdf-template/api';

const POLL_INTERVAL = 15000;

const ExportBatchDetailPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'USER_EXPORT_CSV', 'WORK_HISTORY_EXPORT']);
    const batchId = typeof router.query.id === 'string' ? router.query.id : '';
    const canViewHistory = perms.PDF_TEMPLATE_LIST || perms.USER_EXPORT_CSV || perms.WORK_HISTORY_EXPORT;
    const { data: batchResponse, isLoading } = useGetUserExportBatchQuery(batchId, canViewHistory && router.isReady, {
        pollWhileInProgress: true,
        pollInterval: POLL_INTERVAL,
    });
    const batch = batchResponse?.data;

    const type = batch?.type || '';

    const isCsv = type === ExportBatchTypeEnum.CSV.value;
    const isPdf = type === ExportBatchTypeEnum.PDF.value;
    const isWorkHistory = type === ExportBatchTypeEnum.WORK_HISTORY.value;

    return (
        <Box>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.EXPORT_HISTORY.path}>
                    {t('Export History')}
                </Anchor>
                <Text>
                    {t('Export Batch')} #{batchId}
                </Text>
            </Breadcrumbs>

            {isLoading && (
                <Center h={400}>
                    <Loader />
                </Center>
            )}
            {batch && isCsv && <CsvBatchDetail batch={batch} />}
            {batch && isPdf && <PdfBatchDetail batch={batch} />}
            {batch && isWorkHistory && <WorkHistoryBatchDetail batch={batch} />}
            {!isLoading && !isCsv && !isPdf && !isWorkHistory && (
                <Center h={400}>
                    <Text>{t('Export batch not found')}</Text>
                </Center>
            )}
        </Box>
    );
};

export default ExportBatchDetailPage;
