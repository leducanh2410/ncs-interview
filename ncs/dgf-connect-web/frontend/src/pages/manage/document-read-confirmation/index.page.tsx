import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Paper, Text, Title } from '@mantine/core';
import { useAtom } from 'jotai';
import { DocumentReadConfirmation } from '@/types/models/document_read_confirmation';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { useAllConfirmationsQuery, useDocumentReadConfigsQuery } from '@/pages/manage/document-read-config/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['CONFIG_LIST']);
    const [masterData] = useAtom(masterAtom);

    const { data: configListData } = useDocumentReadConfigsQuery({ page_size: '100' }, perms.CONFIG_LIST);
    const { data, isPending } = useAllConfirmationsQuery(router.query, perms.CONFIG_LIST);

    const configOptions = (configListData?.data?.data || []).map((c: any) => ({
        value: c.id.toString(),
        label: c.title_vi,
    }));

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
                <Text>{t('Confirmations')}</Text>
            </Breadcrumbs>

            <Title order={4} lh="48px" my="md">
                {t('Confirmations')}
            </Title>

            <CommonSearchBox
                initialValues={{
                    config_id: (router.query.config_id as string) || '',
                    workplace_id: '',
                    name: '',
                    staff_no: '',
                }}
                elements={[
                    {
                        id: 'config_id',
                        type: 'select',
                        label: t('Config'),
                        name: 'config_id',
                        options: configOptions,
                        isClearable: true,
                    },
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
                            name: t('Config Title'),
                            key: 'config',
                            render: (row: DocumentReadConfirmation) => row.config?.title_vi || '-',
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

export default IndexPage;
