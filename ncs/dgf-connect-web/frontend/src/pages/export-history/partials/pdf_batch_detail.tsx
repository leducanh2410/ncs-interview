import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload, LuFileWarning } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Box,
    Button,
    Card,
    CardSection,
    Flex,
    Group,
    Modal,
    Paper,
    Progress,
    Stack,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { ExportBatch, ExportBatchItem } from '@/types/models/pdf_template';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ExportBatchItemStatusEnum, getExportBatchItemStatusColor } from '@/enums/export_batch_item_status.enum';
import { ExportBatchSourceEnum, getExportBatchSourceColor } from '@/enums/export_batch_source.enum';
import { ExportBatchStatusEnum, getExportBatchStatusColor } from '@/enums/export_batch_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import ExcelSourceInfo from './excel_source_info';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDownloadBatchZip,
    useDownloadExportItem,
    useGetExportBatchItemsQuery,
} from '@/pages/admin/pdf-template/api';

const POLL_INTERVAL = 15000;

type Props = {
    batch: ExportBatch;
};

const PdfBatchDetail = ({ batch }: Props) => {
    const { t } = useTranslation();
    const router = useRouter();
    const batchId = String(batch.id);

    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'PDF_TEMPLATE_EXPORT']);
    const isInProgress = batch?.status === 'pending' || batch?.status === 'processing';
    const canDownloadZip = batch?.status === 'completed' && batch.completed_count > 0 && perms.PDF_TEMPLATE_EXPORT;
    const isFromExcel = batch.source === ExportBatchSourceEnum.EXCEL.value;

    const { data: itemsResponse, isLoading: isItemsLoading } = useGetExportBatchItemsQuery(
        batchId,
        router.query,
        perms.PDF_TEMPLATE_LIST,
        isInProgress ? POLL_INTERVAL : 0,
    );

    const downloadItemMutation = useDownloadExportItem();
    const downloadZipMutation = useDownloadBatchZip(batchId);
    const [downloadZipUrls, setDownloadZipUrls] = useState<string[]>([]);

    const handleDownloadItem = async (item: ExportBatchItem) => {
        try {
            await downloadItemMutation.mutateAsync(item.id);
        } catch {
            NotificationHelper.showError(t('Could not download PDF'));
        }
    };

    const handleDownloadZip = async () => {
        try {
            const downloadUrls = await downloadZipMutation.mutateAsync();

            if (downloadUrls.length <= 1) {
                if (downloadUrls[0]) {
                    window.open(downloadUrls[0], '_blank', 'noopener,noreferrer');
                }

                return;
            }

            setDownloadZipUrls(downloadUrls);
        } catch {
            NotificationHelper.showError(t('Could not download ZIP file'));
        }
    };

    const handleCloseZipModal = () => {
        setDownloadZipUrls([]);
    };

    const processedCount = (batch?.completed_count ?? 0) + (batch?.failed_count ?? 0);
    const progressPercent = useMemo(() => {
        if (!batch || batch.total_count === 0) return 0;
        return Math.round((processedCount / batch.total_count) * 100);
    }, [batch, processedCount]);

    return (
        <Box>
            <Modal
                opened={downloadZipUrls.length > 1}
                onClose={handleCloseZipModal}
                title={t('Download ZIP files')}
                size="md"
            >
                <Stack>
                    <Text size="sm" c="dimmed">
                        {t('Select a ZIP file to download.')}
                    </Text>
                    {downloadZipUrls.map((downloadUrl, index) => (
                        <Button
                            key={downloadUrl}
                            component="a"
                            href={downloadUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            variant="light"
                            leftSection={<LuDownload size={16} />}
                            fullWidth
                        >
                            {t('ZIP file {{number}}', { number: index + 1 })}
                        </Button>
                    ))}
                </Stack>
            </Modal>

            <Flex justify="space-between" align="center" mt="md">
                <Title order={4}>
                    {t('Export Batch')} #{batch.id}
                </Title>
                <Button
                    leftSection={<LuDownload size={16} />}
                    onClick={handleDownloadZip}
                    disabled={!canDownloadZip}
                    loading={downloadZipMutation.isPending}
                >
                    {t('Download All (ZIP)')}
                </Button>
            </Flex>

            <Card mt="md" maw={{ base: '100%', lg: '50%' }}>
                <CardSection withBorder py="sm" px="md">
                    <Text fw={700}>{t('Export Info')}</Text>
                </CardSection>
                <CardSection p="md">
                    <Stack gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Template')}
                            </Text>
                            <Text size="sm">
                                {batch.pdf_template ? (
                                    <Anchor
                                        component={Link}
                                        href={RouteConfig.ADMIN_PDF_TEMPLATE_DETAIL.path.replace(
                                            '[id]',
                                            String(batch.pdf_template.id),
                                        )}
                                    >
                                        {batch.pdf_template.name}
                                    </Anchor>
                                ) : (
                                    '-'
                                )}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Source')}
                            </Text>
                            <Badge color={getExportBatchSourceColor(batch.source)} variant="light">
                                {EnumHelper.getText(
                                    ExportBatchSourceEnum,
                                    batch.source ?? ExportBatchSourceEnum.MANUAL.value,
                                )}
                            </Badge>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Status')}
                            </Text>
                            <Badge color={getExportBatchStatusColor(batch.status)} variant="light">
                                {EnumHelper.getText(ExportBatchStatusEnum, batch.status)}
                            </Badge>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Exported By')}
                            </Text>
                            <Text size="sm">{batch.exporter?.name ?? '-'}</Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Created At')}
                            </Text>
                            <Text size="sm">
                                {DateHelper.parseAndFormat(batch.created_at, null, t('FORMAT_DATE_WITH_TIME'))}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Progress')}
                            </Text>
                            <Progress.Root size="xl" radius="xl" flex={1}>
                                <Progress.Section
                                    value={progressPercent}
                                    striped={isInProgress}
                                    animated={isInProgress}
                                    color={batch.failed_count > 0 ? 'yellow' : 'blue'}
                                >
                                    <Progress.Label>
                                        {processedCount} / {batch.total_count}
                                    </Progress.Label>
                                </Progress.Section>
                            </Progress.Root>
                        </Group>
                    </Stack>
                </CardSection>
            </Card>

            {isFromExcel && <ExcelSourceInfo batch={batch} canDownload={perms.PDF_TEMPLATE_LIST} />}

            <Paper mt="md" p="md">
                <Title order={5} mb="md">
                    {t('Export Items')}
                </Title>
                <DDataTable<ExportBatchItem>
                    tableKey="admin-pdf-export-batch-items"
                    columns={[
                        {
                            name: t('ID'),
                            key: 'id',
                            align: 'center',
                            width: 80,
                        },
                        {
                            name: t('User'),
                            key: 'user',
                            render: (row) =>
                                row.user ? <UserWithAvatar user={row.user as User} isLink={false} /> : '-',
                        },
                        {
                            name: t('Staff No'),
                            key: 'staff_no',
                            width: 120,
                            render: (row) => row.user?.staff_no ?? '-',
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            width: 120,
                            align: 'center',
                            render: (row) => (
                                <Badge color={getExportBatchItemStatusColor(row.status)} variant="light">
                                    {EnumHelper.getText(ExportBatchItemStatusEnum, row.status)}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Error'),
                            key: 'error_message',
                            render: (row) =>
                                row.status === 'failed' && row.error_message ? (
                                    <Tooltip label={row.error_message} multiline maw={300}>
                                        <Group gap={4} wrap="nowrap" style={{ cursor: 'help' }}>
                                            <LuFileWarning size={14} color="var(--mantine-color-red-6)" />
                                            <Text size="sm" c="red" truncate>
                                                {row.error_message}
                                            </Text>
                                        </Group>
                                    </Tooltip>
                                ) : (
                                    '-'
                                ),
                        },
                        {
                            name: t('Action'),
                            key: 'actions',
                            align: 'center',
                            width: 80,
                            render: (row) =>
                                row.status === 'completed' && perms.PDF_TEMPLATE_EXPORT ? (
                                    <Tooltip label={t('Download PDF')}>
                                        <ActionIcon
                                            variant="light"
                                            color="blue"
                                            onClick={() => handleDownloadItem(row)}
                                            loading={downloadItemMutation.isPending}
                                        >
                                            <LuDownload size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                ) : null,
                        },
                    ]}
                    data={itemsResponse?.data || []}
                    isFetching={isItemsLoading}
                />
            </Paper>
        </Box>
    );
};

export default PdfBatchDetail;
