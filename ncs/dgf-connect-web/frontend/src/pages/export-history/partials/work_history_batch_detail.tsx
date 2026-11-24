import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload, LuFileWarning } from 'react-icons/lu';
import { Badge, Box, Button, Card, CardSection, Center, Flex, Group, Loader, Stack, Text, Title } from '@mantine/core';
import { ExportBatch } from '@/types/models/pdf_template';
import { ExportBatchStatusEnum, getExportBatchStatusColor } from '@/enums/export_batch_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { usePermissions } from '@/hooks/usePermission';
import { useGetWorkHistoryExportDownloadUrlMutation } from '@/pages/export-history/api';

type Props = {
    batch: ExportBatch;
};

const WorkHistoryBatchDetail = ({ batch }: Props) => {
    const { t } = useTranslation();

    const perms = usePermissions(['WORK_HISTORY_EXPORT']);

    const downloadMutation = useGetWorkHistoryExportDownloadUrlMutation();

    const conditions = (batch?.meta?.conditions ?? {}) as { dates?: string[] };
    const dates = Array.isArray(conditions.dates) ? conditions.dates : [];

    const handleDownload = async () => {
        if (!batch || batch.status !== 'completed') return;
        try {
            const response = await downloadMutation.mutateAsync(batch.id);
            const url = response?.data?.download_url;
            if (url) {
                const link = document.createElement('a');
                link.href = url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.click();
                link.remove();
            } else {
                NotificationHelper.showError(t('Could not download export file'));
            }
        } catch {
            NotificationHelper.showError(t('Could not download export file'));
        }
    };

    if (!perms.WORK_HISTORY_EXPORT) {
        return (
            <Center h={400}>
                <Loader />
            </Center>
        );
    }

    const canDownload = batch.status === 'completed';

    return (
        <Box>
            <Flex justify="space-between" align="center" mt="md">
                <Title order={4}>
                    {t('Export Batch')} #{batch.id}
                </Title>
                <Button
                    leftSection={<LuDownload size={16} />}
                    onClick={handleDownload}
                    disabled={!canDownload}
                    loading={downloadMutation.isPending}
                >
                    {t('Download ZIP')}
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
                        {dates.length === 2 && (
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={150}>
                                    {t('Date range')}
                                </Text>
                                <Text size="sm">
                                    {DateHelper.parseAndFormat(dates[0])} ~ {DateHelper.parseAndFormat(dates[1])}
                                </Text>
                            </Group>
                        )}
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Workplace files')}
                            </Text>
                            <Text size="sm">
                                {batch.completed_count} / {batch.total_count}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Created At')}
                            </Text>
                            <Text size="sm">
                                {DateHelper.parseAndFormat(batch.created_at, null, t('FORMAT_DATE_WITH_TIME'))}
                            </Text>
                        </Group>
                        {batch.error_message && (
                            <Group px="sm" py="xs" align="flex-start" wrap="nowrap">
                                <Text size="sm" w={150}>
                                    {t('Error')}
                                </Text>
                                <Flex gap={4} align="center">
                                    <LuFileWarning size={14} color="var(--mantine-color-red-6)" />
                                    <Text size="sm" c="red">
                                        {batch.error_message}
                                    </Text>
                                </Flex>
                            </Group>
                        )}
                    </Stack>
                </CardSection>
            </Card>
        </Box>
    );
};

export default WorkHistoryBatchDetail;
