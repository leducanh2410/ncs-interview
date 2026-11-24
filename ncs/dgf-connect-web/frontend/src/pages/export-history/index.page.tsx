import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Box, Breadcrumbs, Paper, Text, Title } from '@mantine/core';
import { ExportBatch } from '@/types/models/pdf_template';
import RouteConfig from '@/configs/route.config';
import { ExportBatchSourceEnum, getExportBatchSourceColor } from '@/enums/export_batch_source.enum';
import { ExportBatchStatusEnum, getExportBatchStatusColor } from '@/enums/export_batch_status.enum';
import { ExportBatchTypeEnum, getExportBatchTypeColor } from '@/enums/export_batch_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useGetExportHistoryQuery, useGetPdfTemplatesQuery } from '@/pages/admin/pdf-template/api';

const ExportHistoryPage = () => {
    const { t } = useTranslation();
    const router = useRouter();

    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'USER_EXPORT_CSV', 'WORK_HISTORY_EXPORT']);

    const { data: templatesResponse } = useGetPdfTemplatesQuery({ page_size: '100' }, perms.PDF_TEMPLATE_LIST);
    const canViewHistory = perms.PDF_TEMPLATE_LIST || perms.USER_EXPORT_CSV || perms.WORK_HISTORY_EXPORT;
    const { data: historyResponse, isLoading } = useGetExportHistoryQuery(router.query, canViewHistory);

    const templateOptions = useMemo(() => {
        return (templatesResponse?.data?.data || []).map((tpl) => ({
            value: tpl.id.toString(),
            label: tpl.name,
        }));
    }, [templatesResponse]);

    const searchElements = useMemo(() => {
        const typeOptions = [];
        if (perms.PDF_TEMPLATE_LIST) {
            typeOptions.push({ value: ExportBatchTypeEnum.PDF.value, label: ExportBatchTypeEnum.PDF.text });
        }
        if (perms.USER_EXPORT_CSV) {
            typeOptions.push({ value: ExportBatchTypeEnum.CSV.value, label: ExportBatchTypeEnum.CSV.text });
        }
        if (perms.WORK_HISTORY_EXPORT) {
            typeOptions.push({
                value: ExportBatchTypeEnum.WORK_HISTORY.value,
                label: t('Work history'),
            });
        }

        const elements: React.ComponentProps<typeof CommonSearchBox>['elements'] = [
            {
                id: 'type',
                type: 'select',
                label: t('Type'),
                name: 'type',
                options: typeOptions,
                showAll: true,
                allValue: '',
            },
        ];
        if (perms.PDF_TEMPLATE_LIST) {
            const isPdfTypeSelected = (values: Record<string, unknown>) =>
                !values.type || values.type === ExportBatchTypeEnum.PDF.value;
            elements.push({
                id: 'template_id',
                type: 'select',
                label: t('Template'),
                name: 'template_id',
                options: templateOptions,
                visibleWhen: isPdfTypeSelected,
            });
            elements.push({
                id: 'source',
                type: 'select',
                label: t('Source'),
                name: 'source',
                options: [
                    { value: ExportBatchSourceEnum.MANUAL.value, label: t('Manual') },
                    { value: ExportBatchSourceEnum.EXCEL.value, label: t('Excel') },
                ],
                showAll: true,
                allValue: '',
                visibleWhen: isPdfTypeSelected,
            });
        }
        return elements;
    }, [t, templateOptions, perms]);

    return (
        <Box>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Export History')}</Text>
            </Breadcrumbs>

            <Title order={4} lh="48px" my="md">
                {t('Export History')}
            </Title>

            <CommonSearchBox initialValues={{ type: '', template_id: null, source: '' }} elements={searchElements} />

            <Paper mt="md" p="md">
                <DDataTable<ExportBatch>
                    columns={[
                        {
                            name: t('ID'),
                            key: 'id',
                            align: 'center',
                            width: 80,
                        },
                        {
                            name: t('Type'),
                            key: 'type',
                            align: 'center',
                            width: 150,
                            render: (row) => (
                                <Badge color={getExportBatchTypeColor(row.type)} variant="light">
                                    {EnumHelper.getText(ExportBatchTypeEnum, row.type)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Template'),
                            key: 'pdf_template',
                            render: (row) => {
                                if (row.type === ExportBatchTypeEnum.CSV.value) {
                                    return '-';
                                }
                                return row.pdf_template ? (
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('ADMIN_PDF_TEMPLATE_DETAIL', {
                                            id: row.pdf_template.id,
                                        })}
                                        size="sm"
                                    >
                                        {row.pdf_template.name}
                                    </Anchor>
                                ) : (
                                    '-'
                                );
                            },
                        },
                        {
                            name: t('Source'),
                            key: 'source',
                            align: 'center',
                            width: 110,
                            render: (row) =>
                                row.type === ExportBatchTypeEnum.PDF.value ? (
                                    <Badge color={getExportBatchSourceColor(row.source)} variant="light">
                                        {EnumHelper.getText(
                                            ExportBatchSourceEnum,
                                            row.source ?? ExportBatchSourceEnum.MANUAL.value,
                                        )}
                                    </Badge>
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: 120,
                            align: 'center',
                            render: (row) => (
                                <Badge color={getExportBatchStatusColor(row.status)} variant="light">
                                    {EnumHelper.getText(ExportBatchStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Exported By'),
                            key: 'exporter',
                            render: (row) => row.exporter?.name ?? '-',
                        },
                        {
                            name: t('Total'),
                            key: 'total_count',
                            align: 'center',
                            width: 80,
                        },
                        {
                            name: t('Completed'),
                            key: 'completed_count',
                            align: 'center',
                            width: 100,
                            render: (row) => (
                                <Text c="green" size="sm">
                                    {row.completed_count}
                                </Text>
                            ),
                        },
                        {
                            name: t('Failed'),
                            key: 'failed_count',
                            align: 'center',
                            width: 80,
                            render: (row) => (
                                <Text c={row.failed_count > 0 ? 'red' : undefined} size="sm">
                                    {row.failed_count}
                                </Text>
                            ),
                        },
                        {
                            name: t('Created At'),
                            key: 'created_at',
                            width: 160,
                            render: (row) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Action'),
                            key: 'actions',
                            align: 'center',
                            width: 100,
                            render: (row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('EXPORT_BATCH_DETAIL', { id: row.id })}
                                    size="sm"
                                >
                                    {t('View')}
                                </Anchor>
                            ),
                        },
                    ]}
                    data={historyResponse?.data || []}
                    isFetching={isLoading}
                />
            </Paper>
        </Box>
    );
};

export default ExportHistoryPage;
