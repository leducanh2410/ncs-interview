import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileUp, LuHistory, LuLock, LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Group,
    Paper,
    Text,
    Title,
    Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { PdfTemplate } from '@/types/models/pdf_template';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import CreateTemplateModal from '@/pages/admin/pdf-template/partials/create_template_modal';
import ReUploadModal from '@/pages/admin/pdf-template/partials/re_upload_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useDeletePdfTemplateMutation, useGetPdfTemplatesQuery } from '@/pages/admin/pdf-template/api';

const PdfTemplateListPage = () => {
    const { t } = useTranslation();
    const { push, query } = useRouter();
    const perms = usePermissions([
        'PDF_TEMPLATE_LIST',
        'PDF_TEMPLATE_CREATE',
        'PDF_TEMPLATE_UPDATE',
        'PDF_TEMPLATE_DELETE',
        'PDF_TEMPLATE_EXPORT',
    ]);
    const { data, isPending } = useGetPdfTemplatesQuery(query, perms.PDF_TEMPLATE_LIST);
    const deleteMutation = useDeletePdfTemplateMutation();

    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [deleteTemplate, setDeleteTemplate] = useState<PdfTemplate | null>(null);
    const [reUploadTemplate, setReUploadTemplate] = useState<PdfTemplate | null>(null);

    const handleCreateSuccess = (templateId: number) => {
        push(RouteHelper.getPath('ADMIN_PDF_TEMPLATE_DETAIL', { id: templateId }));
    };

    const handleDelete = async () => {
        if (!deleteTemplate) return;
        try {
            await deleteMutation.mutateAsync(deleteTemplate.id);
            NotificationHelper.showSuccess(t('Successfully deleted PDF template'));
        } catch {
            NotificationHelper.showError(t('Could not delete PDF template'));
        } finally {
            setDeleteTemplate(null);
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('PDF Templates')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('PDF Templates')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.PDF_TEMPLATE_CREATE}>
                    {t('Create Template')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{ name: '' }}
                elements={[
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Name'),
                        name: 'name',
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable<PdfTemplate>
                    tableKey="admin-pdf-template-list"
                    columns={[
                        { name: t('ID'), key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_PDF_TEMPLATE_DETAIL', { id: row.id })}
                                >
                                    {row.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Pages'),
                            key: 'page_count',
                            align: 'center',
                            width: 80,
                        },
                        {
                            name: t('Fields'),
                            key: 'field_count',
                            align: 'center',
                            width: 80,
                        },
                        {
                            name: t('Lock'),
                            key: 'lock_info',
                            align: 'center',
                            width: 120,
                            render: (row) =>
                                row.lock_info ? (
                                    <Tooltip label={`${row.lock_info.user_name} (${t(row.lock_info.reason)})`}>
                                        <Badge
                                            leftSection={<LuLock size={12} />}
                                            color="orange"
                                            variant="light"
                                            size="sm"
                                        >
                                            {t(row.lock_info.reason)}
                                        </Badge>
                                    </Tooltip>
                                ) : null,
                        },
                        {
                            name: t('Created At'),
                            key: 'created_at',
                            render: (row) =>
                                DateHelper.parseAndFormat(row.created_at, null, t('FORMAT_DATE_WITH_TIME')),
                            width: 240,
                        },
                        {
                            name: t('Updated At'),
                            key: 'updated_at',
                            render: (row) =>
                                DateHelper.parseAndFormat(row.updated_at, null, t('FORMAT_DATE_WITH_TIME')),
                            width: 160,
                        },
                        {
                            name: t('Action'),
                            key: 'actions',
                            align: 'center',
                            width: 240,
                            render: (row) => (
                                <Group gap={4} justify="center" wrap="nowrap">
                                    <Tooltip label={t('Edit')}>
                                        <ActionIcon
                                            variant="light"
                                            color="blue"
                                            component={Link}
                                            href={RouteHelper.getPath('ADMIN_PDF_TEMPLATE_DETAIL', { id: row.id })}
                                            disabled={!perms.PDF_TEMPLATE_UPDATE}
                                        >
                                            <LuPencilLine size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t('Re-upload PDF')}>
                                        <ActionIcon
                                            variant="light"
                                            color="cyan"
                                            onClick={() => setReUploadTemplate(row)}
                                            disabled={!perms.PDF_TEMPLATE_UPDATE || !!row.lock_info}
                                        >
                                            <LuFileUp size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t('Export History')}>
                                        <ActionIcon
                                            variant="light"
                                            color="grape"
                                            component={Link}
                                            href={`${RouteConfig.EXPORT_HISTORY.path}?template_id=${row.id}`}
                                            disabled={!perms.PDF_TEMPLATE_LIST}
                                        >
                                            <LuHistory size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t('Delete')}>
                                        <ActionIcon
                                            variant="light"
                                            color="red"
                                            onClick={() => setDeleteTemplate(row)}
                                            disabled={!perms.PDF_TEMPLATE_DELETE || !!row.lock_info}
                                        >
                                            <LuTrash2 size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                </Group>
                            ),
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                />
            </Paper>

            <CreateTemplateModal opened={isShowCreate} onClose={closeCreate} onSuccess={handleCreateSuccess} />

            <ReUploadModal
                opened={reUploadTemplate !== null}
                templateId={reUploadTemplate?.id ?? null}
                templateName={reUploadTemplate?.name ?? ''}
                onClose={() => setReUploadTemplate(null)}
            />

            <DConfirmModal
                opened={deleteTemplate !== null}
                title={t('Delete PDF Template')}
                message={t(
                    'Are you sure you want to delete "{{name}}"? This will soft-delete the template. Export history will be preserved.',
                    { name: deleteTemplate?.name },
                )}
                onConfirm={handleDelete}
                onCancel={() => setDeleteTemplate(null)}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={deleteMutation.isPending}
            />
        </>
    );
};

export default PdfTemplateListPage;
