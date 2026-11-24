import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Box, Breadcrumbs, Paper, Text, Title } from '@mantine/core';
import { ImportBatch } from '@/types/models/import_batch';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ImportBatchStatusEnum, getImportBatchStatusColor } from '@/enums/import_batch_status.enum';
import { ImportBatchTypeEnum, getImportBatchTypeColor } from '@/enums/import_batch_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { useAuthLoaded, usePermissions } from '@/hooks/usePermission';
import { useGetImportHistoryQuery } from '@/pages/import-history/api';

const ImportHistoryPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['SHIFT_IMPORT_ORDER']);
    const authLoaded = useAuthLoaded();
    const { data: historyResponse, isLoading } = useGetImportHistoryQuery(
        router.query,
        authLoaded && perms.SHIFT_IMPORT_ORDER,
    );

    return (
        <Box>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Import History')}</Text>
            </Breadcrumbs>

            <Title order={4} lh="48px" my="md">
                {t('Import History')}
            </Title>

            <CommonSearchBox
                initialValues={{ type: '' }}
                elements={[
                    {
                        id: 'type',
                        type: 'select',
                        label: t('Type'),
                        name: 'type',
                        options: [
                            {
                                value: ImportBatchTypeEnum.SHIFT_ORDER.value,
                                label: t('Shift order'),
                            },
                        ],
                        showAll: true,
                        allValue: '',
                    },
                ]}
            />

            <Paper mt="md" p="md">
                <DDataTable<ImportBatch>
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
                                <Badge color={getImportBatchTypeColor(row.type)} variant="light">
                                    {t(EnumHelper.getText(ImportBatchTypeEnum, row.type))}
                                </Badge>
                            ),
                        },
                        {
                            name: t('File'),
                            key: 'file_name',
                            render: (row) => row.file_name,
                        },
                        {
                            name: t('Status'),
                            key: 'status',
                            align: 'center',
                            width: 160,
                            render: (row) => (
                                <Badge color={getImportBatchStatusColor(row.status)} variant="light">
                                    {t(EnumHelper.getText(ImportBatchStatusEnum, row.status))}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Imported By'),
                            key: 'importer',
                            width: 220,
                            render: (row) => (row.importer ? <UserWithAvatar user={row.importer as User} /> : '-'),
                        },
                        {
                            name: t('Created shifts'),
                            key: 'created_count',
                            align: 'center',
                            width: 120,
                        },
                        {
                            name: t('Updated shifts'),
                            key: 'updated_count',
                            align: 'center',
                            width: 120,
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
                                    href={RouteHelper.getPath('IMPORT_BATCH_DETAIL', { id: row.id })}
                                    size="sm"
                                >
                                    {t('View')}
                                </Anchor>
                            ),
                        },
                    ]}
                    data={historyResponse?.data || []}
                    isFetching={isLoading || !authLoaded}
                    noPermission={authLoaded && !perms.SHIFT_IMPORT_ORDER}
                />
            </Paper>
        </Box>
    );
};

export default ImportHistoryPage;
