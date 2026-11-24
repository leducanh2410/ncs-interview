import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload, LuFileSpreadsheet } from 'react-icons/lu';
import { Badge, Button, Card, CardSection, Group, Stack, Tabs, Text } from '@mantine/core';
import { ExcelNameMismatchRow } from '@/types/models/pdf_export_excel';
import { ExportBatch } from '@/types/models/pdf_template';
import NotificationHelper from '@/helpers/notification.helper';
import DDataTable from '@/components/DDataTable';
import ExcelDuplicatesTable from '@/pages/admin/pdf-export-excel/partials/excel_duplicates_table';
import ExcelUnmatchedTable from '@/pages/admin/pdf-export-excel/partials/excel_unmatched_table';
import { useDownloadSourceFileMutation } from '@/pages/admin/pdf-export-excel/api';

type Props = {
    batch: ExportBatch;
    canDownload: boolean;
};

const ExcelSourceInfo = ({ batch, canDownload }: Props) => {
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState<string | null>('unmatched');
    const downloadMutation = useDownloadSourceFileMutation();

    const excel = batch.meta?.excel;
    const summary = excel?.summary;
    const fileName = batch.meta?.source_file_name ?? '-';

    const handleDownload = async () => {
        try {
            await downloadMutation.mutateAsync(batch.id);
        } catch {
            NotificationHelper.showError(t('Could not download input file'));
        }
    };

    return (
        <Card mt="md">
            <CardSection withBorder py="sm" px="md">
                <Group justify="space-between">
                    <Group gap="xs">
                        <LuFileSpreadsheet size={16} />
                        <Text fw={700}>{t('Excel input')}</Text>
                    </Group>
                    <Button
                        size="xs"
                        variant="light"
                        leftSection={<LuDownload size={14} />}
                        onClick={handleDownload}
                        disabled={!canDownload}
                        loading={downloadMutation.isPending}
                    >
                        {t('Download input file')}
                    </Button>
                </Group>
            </CardSection>
            <CardSection p="md">
                <Stack gap="xs">
                    <Group px="sm" py="xs" bg="gray.2">
                        <Text size="sm" w={150}>
                            {t('File name')}
                        </Text>
                        <Text size="sm">{fileName}</Text>
                    </Group>
                    {summary && (
                        <Group px="sm" py="xs" gap="xs">
                            <Text size="sm" w={150}>
                                {t('Summary')}
                            </Text>
                            <Badge variant="light" color="gray">
                                {t('Rows in file')}: {summary.total_rows}
                            </Badge>
                            <Badge variant="light" color="green">
                                {t('Matched users')}: {summary.matched_count}
                            </Badge>
                            <Badge variant="light" color={summary.unmatched_count > 0 ? 'red' : 'gray'}>
                                {t('Not found')}: {summary.unmatched_count}
                            </Badge>
                            <Badge variant="light" color={summary.duplicate_count > 0 ? 'orange' : 'gray'}>
                                {t('Duplicate rows')}: {summary.duplicate_count}
                            </Badge>
                            <Badge variant="light" color={summary.name_mismatch_count > 0 ? 'orange' : 'gray'}>
                                {t('Name mismatches')}: {summary.name_mismatch_count}
                            </Badge>
                        </Group>
                    )}
                    {excel?.truncated && (
                        <Text size="xs" c="dimmed" px="sm">
                            {t('Only the first rows of each list were stored in the history.')}
                        </Text>
                    )}
                </Stack>

                {excel && (
                    <Tabs value={activeTab} onChange={setActiveTab} mt="md">
                        <Tabs.List>
                            <Tabs.Tab value="unmatched">
                                {t('Not found')} ({excel.unmatched.length})
                            </Tabs.Tab>
                            <Tabs.Tab value="duplicates">
                                {t('Duplicates')} ({excel.duplicates.length})
                            </Tabs.Tab>
                            <Tabs.Tab value="name_mismatches">
                                {t('Name mismatches')} ({excel.name_mismatches.length})
                            </Tabs.Tab>
                        </Tabs.List>
                        <Tabs.Panel value="unmatched" pt="md">
                            <ExcelUnmatchedTable rows={excel.unmatched} />
                        </Tabs.Panel>
                        <Tabs.Panel value="duplicates" pt="md">
                            <ExcelDuplicatesTable tableKey="export-history-excel-duplicates" rows={excel.duplicates} />
                        </Tabs.Panel>
                        <Tabs.Panel value="name_mismatches" pt="md">
                            <DDataTable<ExcelNameMismatchRow>
                                tableKey="export-history-excel-name-mismatches"
                                columns={[
                                    { name: t('Row'), key: 'row_number', width: 80, align: 'center' },
                                    { name: t('Staff No (file)'), key: 'staff_no', width: 140 },
                                    { name: t('Name (file)'), key: 'name', render: (row) => row.name ?? '-' },
                                    { name: t('User (system)'), key: 'user', render: (row) => row.user.name },
                                ]}
                                data={excel.name_mismatches}
                                isFetching={false}
                            />
                        </Tabs.Panel>
                    </Tabs>
                )}
            </CardSection>
        </Card>
    );
};

export default ExcelSourceInfo;
