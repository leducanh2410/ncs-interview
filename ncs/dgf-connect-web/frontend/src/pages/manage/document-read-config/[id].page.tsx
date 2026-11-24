import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Badge, Breadcrumbs, Flex, Paper, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai';
import { DocumentReadConfirmation } from '@/types/models/document_read_confirmation';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import StatsPanel from '@/pages/manage/document-read-config/partials/stats_panel';
import { usePermissions } from '@/hooks/usePermission';
import { useAllConfirmationsQuery, useDocumentReadConfigQuery } from '@/pages/manage/document-read-config/api';

const DetailPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const perms = usePermissions(['CONFIG_LIST']);
    const [masterData] = useAtom(masterAtom);

    const { data: configData } = useDocumentReadConfigQuery(id, perms.CONFIG_LIST);
    const config = configData?.data;

    const { id: _routeId, ...searchQuery } = router.query;
    const { data, isPending } = useAllConfirmationsQuery({ ...searchQuery, config_id: id }, perms.CONFIG_LIST && !!id);

    const workplaceOptions = [
        { value: '', label: t('System-wide') },
        ...masterData.workplaces.map((w) => ({
            value: w.id.toString(),
            label: w.name,
        })),
    ];

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_DOCUMENT_READ_CONFIG.path}>
                    {t('Document Read Config')}
                </Anchor>
                <Text>{config?.title_vi || t('Detail')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center" my="md">
                <Flex align="center" gap="sm">
                    <Title order={4} lh="48px">
                        {config?.title_vi || t('Document Read Config')}
                    </Title>
                    {config && (
                        <Badge color={config.scope === 'system' ? 'blue' : 'green'} variant="light">
                            {config.scope === 'system' ? t('System-wide') : t('Workplace')}
                        </Badge>
                    )}
                </Flex>
            </Flex>

            <StatsPanel configId={id ? Number(id) : null} opened={!!id} />

            <Title order={5} mt="lg" mb="sm">
                {t('Confirmations')}
            </Title>

            <CommonSearchBox
                initialValues={{
                    workplace_id: '',
                    name: '',
                    staff_no: '',
                }}
                elements={[
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: workplaceOptions,
                        isClearable: true,
                    },
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Employee name'),
                        name: 'name',
                    },
                    {
                        id: 'staff_no',
                        type: 'input-text',
                        label: t('Employee ID'),
                        name: 'staff_no',
                    },
                ]}
            />

            <Paper mt="md" p="md">
                <DDataTable
                    columns={[
                        {
                            name: 'ID',
                            key: 'id',
                            align: 'center',
                            width: '60px',
                        },
                        {
                            name: t('Employee'),
                            key: 'user',
                            render: (row: DocumentReadConfirmation) => {
                                if (!row.user) return '-';
                                const parts = [row.user.name];
                                if (row.user.staff_no) parts.push(`(${row.user.staff_no})`);
                                return parts.join(' ');
                            },
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (row: DocumentReadConfirmation) =>
                                row.config?.workplace_id ? row.config.workplace?.name || '-' : t('System-wide'),
                            width: '150px',
                        },
                        {
                            name: t('Confirmed At'),
                            key: 'created_at',
                            render: (row: DocumentReadConfirmation) => {
                                const parsed = DateHelper.parse(row.created_at, null);
                                return parsed ? DateHelper.format(parsed, DATE_STORE_FORMAT_WITH_TIME) : '-';
                            },
                            width: '180px',
                        },
                        {
                            name: t('IP Address'),
                            key: 'ip_address',
                            width: '140px',
                        },
                        {
                            name: t('Device'),
                            key: 'device_info',
                            render: (row: DocumentReadConfirmation) => {
                                if (!row.device_info) return '-';
                                const parts = [
                                    row.device_info.model,
                                    row.device_info.os,
                                    row.device_info.app_version,
                                ].filter(Boolean);
                                return parts.length > 0 ? parts.join(' / ') : '-';
                            },
                            width: '200px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>
        </>
    );
};

export default DetailPage;
