import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuEye, LuPlus, LuRefreshCw, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Badge, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { DocumentRequiredConfig } from '@/types/models/document_required_config';
import RouteConfig from '@/configs/route.config';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import BackfillModal from '@/pages/manage/document-read-config/partials/backfill_modal';
import ConfigFormModal from '@/pages/manage/document-read-config/partials/config_form_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDeleteDocumentReadConfigMutation,
    useDocumentReadConfigsQuery,
} from '@/pages/manage/document-read-config/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['CONFIG_LIST', 'CONFIG_EDIT']);
    const [masterData] = useAtom(masterAtom);
    const { data, isPending } = useDocumentReadConfigsQuery(router.query, perms.CONFIG_LIST);
    const { mutateAsync: deleteConfig } = useDeleteDocumentReadConfigMutation();

    const [deleteId, setDeleteId] = React.useState<string | null>(null);
    const [editConfig, setEditConfig] = React.useState<DocumentRequiredConfig | null>(null);
    const [backfillId, setBackfillId] = React.useState<number | null>(null);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);

    const scopeOptions = [
        { value: 'all', label: t('All') },
        { value: 'system', label: t('System-wide') },
        { value: 'workplace', label: t('Workplace') },
    ];

    const closeEdit = () => setEditConfig(null);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Document Read Config')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Document Read Config')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.CONFIG_EDIT}>
                    {t('Create Config')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{ scope: 'all' }}
                elements={[
                    {
                        id: 'scope',
                        type: 'select',
                        label: t('Type'),
                        name: 'scope',
                        options: scopeOptions,
                        isClearable: false,
                        showAll: false,
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
                            name: t('Type'),
                            key: 'scope',
                            render: (row: DocumentRequiredConfig) => (
                                <Badge color={row.scope === 'system' ? 'blue' : 'green'} variant="light">
                                    {row.scope === 'system' ? t('System-wide') : t('Workplace')}
                                </Badge>
                            ),
                            width: '120px',
                        },
                        {
                            name: t('Workplace'),
                            key: 'workplace',
                            render: (row: DocumentRequiredConfig) => row.workplace?.name || '-',
                            width: '150px',
                        },
                        {
                            name: t('Title (VI)'),
                            key: 'title_vi',
                            render: (row: DocumentRequiredConfig) => row.title_vi,
                        },
                        {
                            name: t('Document'),
                            key: 'document',
                            render: (row: DocumentRequiredConfig) =>
                                row.document ? row.document.title : <Text c="red">{t('Document deleted')}</Text>,
                        },
                        {
                            name: t('Status'),
                            key: 'is_active',
                            render: (row: DocumentRequiredConfig) => (
                                <Badge color={row.is_active ? 'teal' : 'gray'} variant="light">
                                    {row.is_active ? t('Active') : t('Inactive')}
                                </Badge>
                            ),
                            width: '100px',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: DocumentRequiredConfig) => (
                                <Flex gap={4}>
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => setEditConfig(row)}
                                        disabled={!perms.CONFIG_EDIT}
                                        title={t('Edit')}
                                    >
                                        <LuPlus size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="teal"
                                        onClick={() => setBackfillId(row.id)}
                                        disabled={!perms.CONFIG_EDIT || !row.is_active}
                                        title={t('Backfill')}
                                    >
                                        <LuRefreshCw size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="violet"
                                        component={Link}
                                        href={RouteHelper.getPath('MANAGE_DOCUMENT_READ_CONFIG_DETAIL', { id: row.id })}
                                        title={t('View detail')}
                                    >
                                        <LuEye size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(row.id.toString())}
                                        disabled={!perms.CONFIG_EDIT}
                                        title={t('Delete')}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                            width: '200px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>

            <ConfigFormModal config={null} opened={isShowCreate} onClose={closeCreate} />
            <ConfigFormModal config={editConfig} opened={editConfig !== null} onClose={closeEdit} />

            <BackfillModal configId={backfillId} opened={backfillId !== null} onClose={() => setBackfillId(null)} />

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete document read config')}
                message={t(
                    'Are you sure you want to delete this config? All associated confirmation records will also be deleted.',
                )}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteConfig(deleteId);
                        notifications.show({ color: 'teal', message: t('Successfully deleted config') });
                    } catch (e) {
                        notifications.show({ color: 'red', message: t('error|Could not delete config') });
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
