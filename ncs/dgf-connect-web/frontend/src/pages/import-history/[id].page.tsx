import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Badge,
    Box,
    Breadcrumbs,
    Button,
    Card,
    CardSection,
    Center,
    Flex,
    Group,
    Loader,
    Stack,
    Table,
    Text,
    Title,
} from '@mantine/core';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ImportBatchStatusEnum, getImportBatchStatusColor } from '@/enums/import_batch_status.enum';
import { ImportBatchTypeEnum } from '@/enums/import_batch_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import UserWithAvatar from '@/components/UserWithAvatar';
import { useAuthLoaded, usePermissions } from '@/hooks/usePermission';
import { useGetImportBatchDownloadUrlMutation, useGetImportBatchQuery } from '@/pages/import-history/api';

const ImportBatchDetailPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['SHIFT_IMPORT_ORDER']);
    const authLoaded = useAuthLoaded();
    const batchId = typeof router.query.id === 'string' ? router.query.id : '';
    const { data, isLoading } = useGetImportBatchQuery(
        batchId,
        authLoaded && perms.SHIFT_IMPORT_ORDER && router.isReady,
    );
    const downloadMutation = useGetImportBatchDownloadUrlMutation();

    const detail = data?.data;
    const batch = detail?.batch;
    const isResolving = !authLoaded || isLoading;
    const canView = authLoaded && perms.SHIFT_IMPORT_ORDER;

    const handleDownload = async () => {
        if (!batch) {
            return;
        }

        try {
            const response = await downloadMutation.mutateAsync(batch.id);
            const url = response?.data?.download_url;
            if (!url) {
                NotificationHelper.showError(t('Could not download the imported file'));
                return;
            }
            const link = document.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.click();
            link.remove();
        } catch {
            NotificationHelper.showError(t('Could not download the imported file'));
        }
    };

    return (
        <Box>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.IMPORT_HISTORY.path}>
                    {t('Import History')}
                </Anchor>
                <Text>
                    {t('Import Batch')} #{batchId}
                </Text>
            </Breadcrumbs>

            {isResolving && (
                <Center h={400}>
                    <Loader />
                </Center>
            )}

            {!isResolving && !canView && (
                <Center h={400}>
                    <Text>{t('You do not have permission to access this page')}</Text>
                </Center>
            )}

            {!isResolving && canView && !batch && (
                <Center h={400}>
                    <Text>{t('Import batch not found')}</Text>
                </Center>
            )}

            {batch && detail && (
                <>
                    <Title order={4} lh="48px" my="md">
                        {t(EnumHelper.getText(ImportBatchTypeEnum, batch.type))}
                    </Title>

                    <Card withBorder mb="md">
                        <CardSection withBorder py="sm" px="md">
                            <Flex justify="space-between" align="center">
                                <Text fw={700}>{t('Import Info')}</Text>
                                <Button
                                    size="xs"
                                    variant="light"
                                    leftSection={<LuDownload size={14} />}
                                    onClick={handleDownload}
                                    loading={downloadMutation.isPending}
                                >
                                    {t('Download file')}
                                </Button>
                            </Flex>
                        </CardSection>
                        <CardSection p="md">
                            <Stack gap={0}>
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={180}>
                                        {t('File')}
                                    </Text>
                                    <Text size="sm">{batch.file_name}</Text>
                                </Group>
                                <Group px="sm" py="xs">
                                    <Text size="sm" w={180}>
                                        {t('Month')}
                                    </Text>
                                    <Text size="sm">{detail.month ?? '-'}</Text>
                                </Group>
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={180}>
                                        {t('Status')}
                                    </Text>
                                    <Badge color={getImportBatchStatusColor(batch.status)} variant="light">
                                        {t(EnumHelper.getText(ImportBatchStatusEnum, batch.status))}
                                    </Badge>
                                </Group>
                                <Group px="sm" py="xs">
                                    <Text size="sm" w={180}>
                                        {t('Imported By')}
                                    </Text>
                                    {batch.importer ? (
                                        <UserWithAvatar user={batch.importer as User} />
                                    ) : (
                                        <Text size="sm">-</Text>
                                    )}
                                </Group>
                                <Group px="sm" py="xs" bg="gray.2">
                                    <Text size="sm" w={180}>
                                        {t('Created At')}
                                    </Text>
                                    <Text size="sm">
                                        {DateHelper.parseAndFormat(batch.created_at, null, t('FORMAT_TIMESTAMP'))}
                                    </Text>
                                </Group>
                                <Group px="sm" py="xs" align="flex-start">
                                    <Text size="sm" w={180}>
                                        {t('Result')}
                                    </Text>
                                    <Stack gap={2}>
                                        <Text size="sm">
                                            {t('Created shifts')}: {batch.created_count}
                                        </Text>
                                        <Text size="sm">
                                            {t('Updated shifts')}: {batch.updated_count}
                                        </Text>
                                        {detail.skipped_count > 0 && (
                                            <Text size="sm">
                                                {t('Kept as in the system')}: {detail.skipped_count}
                                            </Text>
                                        )}
                                    </Stack>
                                </Group>
                            </Stack>
                        </CardSection>
                    </Card>

                    <Card withBorder>
                        <CardSection withBorder py="sm" px="md">
                            <Text fw={700}>{t('Workplaces')}</Text>
                        </CardSection>
                        <CardSection p="md">
                            <Table withTableBorder withColumnBorders striped>
                                <Table.Thead>
                                    <Table.Tr>
                                        <Table.Th>{t('Workplace')}</Table.Th>
                                        <Table.Th w={140}>{t('Applied')}</Table.Th>
                                        <Table.Th w={140}>{t('Kept')}</Table.Th>
                                    </Table.Tr>
                                </Table.Thead>
                                <Table.Tbody>
                                    {detail.workplaces.map((workplace) => (
                                        <Table.Tr key={workplace.workplace_id}>
                                            <Table.Td>{workplace.workplace_name}</Table.Td>
                                            <Table.Td>{workplace.applied_count}</Table.Td>
                                            <Table.Td>{workplace.skipped_count}</Table.Td>
                                        </Table.Tr>
                                    ))}
                                </Table.Tbody>
                            </Table>
                        </CardSection>
                    </Card>
                </>
            )}
        </Box>
    );
};

export default ImportBatchDetailPage;
