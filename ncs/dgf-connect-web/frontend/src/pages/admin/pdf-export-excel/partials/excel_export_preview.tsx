import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, Badge, Button, Flex, Group, Paper, SimpleGrid, Stack, Tabs, Text } from '@mantine/core';
import { ExcelExportPreview as ExcelExportPreviewData, ExcelMatchedRow } from '@/types/models/pdf_export_excel';
import DDataTable from '@/components/DDataTable';
import ExcelDuplicatesTable from './excel_duplicates_table';
import ExcelUnmatchedTable from './excel_unmatched_table';

type Props = {
    preview: ExcelExportPreviewData;
    onBack: () => void;
    onConfirm: () => void;
    isConfirming: boolean;
};

type SummaryTileProps = {
    label: string;
    value: number;
    color?: string;
};

const SummaryTile = ({ label, value, color }: SummaryTileProps) => (
    <Paper withBorder p="sm">
        <Text size="xs" c="dimmed">
            {label}
        </Text>
        <Text size="xl" fw={700} c={color}>
            {value}
        </Text>
    </Paper>
);

const ExcelExportPreview = ({ preview, onBack, onConfirm, isConfirming }: Props) => {
    const { t } = useTranslation();
    const [activeTab, setActiveTab] = useState<string | null>('matched');
    const { summary } = preview;

    const warningCount = summary.disabled_count + summary.name_mismatch_count + summary.numeric_match_count;

    const matchedRowStyle = useMemo(
        () => (row: ExcelMatchedRow) =>
            row.name_mismatch || row.is_disabled || row.matched_by === 'numeric'
                ? { backgroundColor: 'var(--mantine-color-yellow-0)' }
                : undefined,
        [],
    );

    return (
        <Stack gap="md">
            <Paper p="md">
                <Group justify="space-between" mb="sm">
                    <div>
                        <Text fw={700}>{preview.template.name}</Text>
                        <Text size="sm" c="dimmed">
                            {preview.file_name}
                        </Text>
                    </div>
                </Group>
                <SimpleGrid cols={{ base: 2, sm: 3, lg: 6 }}>
                    <SummaryTile label={t('Rows in file')} value={summary.total_rows} />
                    <SummaryTile label={t('Matched users')} value={summary.matched_count} color="green" />
                    <SummaryTile
                        label={t('Not found')}
                        value={summary.unmatched_count}
                        color={summary.unmatched_count > 0 ? 'red' : undefined}
                    />
                    <SummaryTile
                        label={t('Duplicate rows')}
                        value={summary.duplicate_count}
                        color={summary.duplicate_count > 0 ? 'orange' : undefined}
                    />
                    <SummaryTile
                        label={t('Disabled users')}
                        value={summary.disabled_count}
                        color={summary.disabled_count > 0 ? 'orange' : undefined}
                    />
                    <SummaryTile
                        label={t('Name mismatches')}
                        value={summary.name_mismatch_count}
                        color={summary.name_mismatch_count > 0 ? 'orange' : undefined}
                    />
                </SimpleGrid>

                {preview.lock_info && (
                    <Alert mt="md" variant="light" color="red" icon={<LuTriangleAlert size={16} />}>
                        {t('Template is currently locked by {{name}}. Please try again later.', {
                            name: preview.lock_info.user_name,
                        })}
                    </Alert>
                )}
                {summary.matched_count === 0 && (
                    <Alert mt="md" variant="light" color="red" icon={<LuTriangleAlert size={16} />}>
                        {t('No user in the file matches a staff number in the system. Nothing can be exported.')}
                    </Alert>
                )}
                {summary.matched_count > 0 && warningCount > 0 && (
                    <Alert mt="md" variant="light" color="yellow" icon={<LuTriangleAlert size={16} />}>
                        {t(
                            'Highlighted rows have warnings (name differs, user is disabled, or the staff number matched only after removing leading zeros). They will still be exported.',
                        )}
                    </Alert>
                )}
            </Paper>

            <Paper p="md">
                <Tabs value={activeTab} onChange={setActiveTab}>
                    <Tabs.List>
                        <Tabs.Tab value="matched">
                            {t('Matched')} ({summary.matched_count})
                        </Tabs.Tab>
                        <Tabs.Tab value="unmatched">
                            {t('Not found')} ({summary.unmatched_count})
                        </Tabs.Tab>
                        <Tabs.Tab value="duplicates">
                            {t('Duplicates')} ({summary.duplicate_count})
                        </Tabs.Tab>
                    </Tabs.List>

                    <Tabs.Panel value="matched" pt="md">
                        <DDataTable<ExcelMatchedRow>
                            tableKey="pdf-export-excel-matched"
                            columns={[
                                { name: t('Row'), key: 'row_number', width: 80, align: 'center' },
                                { name: t('Staff No (file)'), key: 'staff_no', width: 140 },
                                {
                                    name: t('Name (file)'),
                                    key: 'name',
                                    render: (row) => row.name ?? '-',
                                },
                                {
                                    name: t('User (system)'),
                                    key: 'user',
                                    render: (row) => (
                                        <Text size="sm" c={row.name_mismatch ? 'orange' : undefined}>
                                            {row.user.name}
                                        </Text>
                                    ),
                                },
                                {
                                    name: t('Staff No (system)'),
                                    key: 'user_staff_no',
                                    width: 150,
                                    render: (row) => row.user.staff_no ?? '-',
                                },
                                {
                                    name: t('Warnings'),
                                    key: 'warnings',
                                    render: (row) => (
                                        <Group gap={4}>
                                            {row.name_mismatch && (
                                                <Badge color="orange" variant="light">
                                                    {t('Name differs')}
                                                </Badge>
                                            )}
                                            {row.is_disabled && (
                                                <Badge color="gray" variant="light">
                                                    {t('Disabled')}
                                                </Badge>
                                            )}
                                            {row.matched_by === 'numeric' && (
                                                <Badge color="yellow" variant="light">
                                                    {t('Leading zeros')}
                                                </Badge>
                                            )}
                                        </Group>
                                    ),
                                },
                            ]}
                            data={preview.matched}
                            isFetching={false}
                            rowStyle={matchedRowStyle}
                        />
                    </Tabs.Panel>

                    <Tabs.Panel value="unmatched" pt="md">
                        <ExcelUnmatchedTable rows={preview.unmatched} />
                    </Tabs.Panel>

                    <Tabs.Panel value="duplicates" pt="md">
                        <ExcelDuplicatesTable tableKey="pdf-export-excel-duplicates" rows={preview.duplicates} />
                    </Tabs.Panel>
                </Tabs>
            </Paper>

            <Flex justify="space-between">
                <Button variant="default" onClick={onBack} disabled={isConfirming}>
                    {t('Back')}
                </Button>
                <Button onClick={onConfirm} disabled={!preview.can_export} loading={isConfirming}>
                    {t('Export {{count}} PDF files', { count: summary.matched_count })}
                </Button>
            </Flex>
        </Stack>
    );
};

export default ExcelExportPreview;
