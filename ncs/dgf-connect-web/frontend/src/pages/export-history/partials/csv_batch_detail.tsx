import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload, LuFileWarning } from 'react-icons/lu';
import {
    Badge,
    Box,
    Button,
    Card,
    CardSection,
    Center,
    Flex,
    Group,
    Loader,
    SimpleGrid,
    Stack,
    Table,
    Text,
    Title,
} from '@mantine/core';
import { ExportBatch } from '@/types/models/pdf_template';
import { ExportBatchStatusEnum, getExportBatchStatusColor } from '@/enums/export_batch_status.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { getCsvPropertyLabel } from '@/pages/admin/csv-export/csv_property_label';
import { usePermissions } from '@/hooks/usePermission';
import { useGetCsvExportDownloadUrlMutation } from '@/pages/admin/csv-export/api';

type Props = {
    batch: ExportBatch;
};

const CsvBatchDetail = ({ batch }: Props) => {
    const { t } = useTranslation();

    const perms = usePermissions(['USER_EXPORT_CSV']);

    const downloadMutation = useGetCsvExportDownloadUrlMutation();

    const exportKeys = useMemo(() => batch?.meta?.export_keys ?? [], [batch]);
    const userIds = batch?.meta?.user_ids ?? [];
    const encoding = batch?.meta?.encoding;
    const users = batch?.users ?? [];

    const handleDownload = async () => {
        if (!batch || batch.status !== 'completed') return;
        try {
            const response = await downloadMutation.mutateAsync(batch.id);
            const url = response?.data?.download_url;
            if (url) {
                window.open(url, '_blank', 'noopener,noreferrer');
            } else {
                NotificationHelper.showError(t('Could not download CSV'));
            }
        } catch {
            NotificationHelper.showError(t('Could not download CSV'));
        }
    };

    if (!perms.USER_EXPORT_CSV) {
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
                    {t('Download CSV')}
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
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Total')}
                            </Text>
                            <Text size="sm">{batch.total_count}</Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Created At')}
                            </Text>
                            <Text size="sm">
                                {DateHelper.parseAndFormat(batch.created_at, null, t('FORMAT_DATE_WITH_TIME'))}
                            </Text>
                        </Group>
                        {encoding && (
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={150}>
                                    {t('Encoding')}
                                </Text>
                                <Text size="sm">{encoding.toUpperCase()}</Text>
                            </Group>
                        )}
                        {batch.error_message && (
                            <Group px="sm" py="xs" bg="gray.2" align="flex-start" wrap="nowrap">
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

            <Card mt="md">
                <CardSection withBorder py="sm" px="md">
                    <Text fw={700}>{t('Selected Properties')}</Text>
                </CardSection>
                <CardSection p="md">
                    {exportKeys.length === 0 ? (
                        <Text size="sm" c="dimmed">
                            {t('No properties selected')}
                        </Text>
                    ) : (
                        <SimpleGrid
                            cols={{ base: 1, sm: 2, lg: 3 }}
                            spacing="xs"
                            verticalSpacing="xs"
                            style={{ border: '1px solid var(--mantine-color-gray-3)', borderRadius: 6, padding: 8 }}
                        >
                            {exportKeys.map((key, index) => (
                                <Group key={key} gap="xs" wrap="nowrap">
                                    <Text size="xs" c="dimmed" ta="right" w={28} ff="monospace">
                                        {index + 1}
                                    </Text>
                                    <Text size="sm">{getCsvPropertyLabel(key, t)}</Text>
                                </Group>
                            ))}
                        </SimpleGrid>
                    )}
                </CardSection>
            </Card>

            {userIds.length > 0 && (
                <Card mt="md">
                    <CardSection withBorder py="sm" px="md">
                        <Text fw={700}>{t('User Selection')}</Text>
                    </CardSection>
                    <CardSection p="md">
                        {users.length > 0 ? (
                            <Table striped highlightOnHover>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>{t('ID')}</Table.Th>
                                        <Table.Th>{t('Name')}</Table.Th>
                                        <Table.Th>{t('Staff No')}</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {users.map((user) => (
                                        <Table.Tr key={user.id}>
                                            <Table.Td>{user.id}</Table.Td>
                                            <Table.Td>{user.name}</Table.Td>
                                            <Table.Td>{user.staff_no ?? '-'}</Table.Td>
                                        </Table.Tr>
                                    ))}
                                </Table.Tbody>
                            </Table>
                        ) : (
                            <Text size="sm" c="dimmed">
                                {t('{{count}} selected users', {
                                    count: userIds.length,
                                })}
                            </Text>
                        )}
                    </CardSection>
                </Card>
            )}
        </Box>
    );
};

export default CsvBatchDetail;
