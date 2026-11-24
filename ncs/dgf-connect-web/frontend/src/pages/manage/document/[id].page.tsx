import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Breadcrumbs,
    Button,
    Card,
    CardSection,
    Flex,
    Pagination,
    Paper,
    Text,
    Title,
} from '@mantine/core';
import { Document } from '@/types/models/document';
import RouteConfig from '@/configs/route.config';
import NotificationHelper from '@/helpers/notification.helper';
import UrlHelper from '@/helpers/url.helper';
import DBlockLoader from '@/components/DBlockLoader';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import HtmlPreview from '@/pages/manage/document/partials/DocumentPreview';
import UpdateDocumentModal from '@/pages/manage/document/partials/update_document_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useGetDocumentQuery, useUpdateDocumentMutation } from '@/pages/manage/document/api';

const DocumentDetailPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;

    const [isShowUpdate, setIsShowUpdate] = useState(false);
    const [removeAttachmentId, setRemoveAttachmentId] = useState<number | null>(null);
    const [removeUserId, setRemoveUserId] = useState<number | null>(null);
    const [removeWorkplaceId, setRemoveWorkplaceId] = useState<number | null>(null);
    const [confirmDeleteOpen, setConfirmDeleteOpen] = useState(false);
    const [isProcessing, setIsProcessing] = useState(false);

    // Separate pagination state for each table
    const [userPage, setUserPage] = useState(1);
    const [workplacePage, setWorkplacePage] = useState(1);
    const { data, isPending } = useGetDocumentQuery(id);

    const updateMutation = useUpdateDocumentMutation(id);
    const perms = usePermissions(['DOCUMENT_LIST', 'DOCUMENT_CREATE', 'DOCUMENT_EDIT', 'DOCUMENT_DELETE']);
    if (isPending || !data || !data.data) {
        return (
            <>
                <Breadcrumbs>
                    <Anchor component={Link} href={RouteConfig.HOME.path}>
                        {t('Home')}
                    </Anchor>
                    <Anchor component={Link} href={RouteConfig.MANAGE_DOCUMENT.path}>
                        {t('Document management')}
                    </Anchor>
                    <Text>{t('Document detail')}</Text>
                </Breadcrumbs>
                <Title order={4} lh="48px" my="md">
                    {t('Document detail')}
                </Title>
                <DBlockLoader height="400px" />
            </>
        );
    }

    const document: Document = data.data;

    const pageSize = 10;
    const paginatedUsers = document.users.slice((userPage - 1) * pageSize, userPage * pageSize);
    const paginatedWorkplaces = document.workplaces.slice((workplacePage - 1) * pageSize, workplacePage * pageSize);

    const handleRemove = async () => {
        if (removeAttachmentId === null && removeUserId === null && removeWorkplaceId === null) return;
        setIsProcessing(true);
        try {
            const formData = new FormData();
            formData.append('id', document.id.toString());

            if (removeAttachmentId !== null) {
                formData.append('remove_attachments[]', removeAttachmentId.toString());
            }

            if (removeUserId !== null) {
                formData.append('remove_users[]', removeUserId.toString());
            }

            if (removeWorkplaceId !== null) {
                formData.append('remove_workplaces[]', removeWorkplaceId.toString());
            }

            await updateMutation.mutateAsync(formData);
            NotificationHelper.showSuccess(t('Document updated'));
        } catch (e) {
            NotificationHelper.showError(t('Failed to update document'));
        } finally {
            setIsProcessing(false);
            setConfirmDeleteOpen(false);
            setRemoveAttachmentId(null);
            setRemoveUserId(null);
            setRemoveWorkplaceId(null);
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.MANAGE_DOCUMENT.path}>
                    {t('Document management')}
                </Anchor>
                <Text>{t('Document detail')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center" my="md">
                <Title order={3}>{t('Document detail')}</Title>
                <Button
                    onClick={() => setIsShowUpdate(true)}
                    variant="light"
                    leftSection={<LuPencil size={16} />}
                    disabled={!perms.DOCUMENT_EDIT}
                >
                    {t('Edit')}
                </Button>
            </Flex>

            <Card shadow="sm" withBorder>
                <CardSection withBorder py="sm" px="md" bg="gray.1">
                    <Text fw={700} size="lg">
                        {document.title}
                    </Text>
                </CardSection>

                <Paper p="md" my="lg" shadow="xs" withBorder>
                    <Title order={5} mb="md">
                        {t('File name')}
                    </Title>
                    <DDataTable
                        highlightOnHover={true}
                        columns={[
                            {
                                name: t('File name'),
                                key: 'filename',
                                render: (row) => <Anchor href={UrlHelper.getStaticUrl(row.url)}>{row.filename}</Anchor>,
                            },
                            {
                                name: t('Action'),
                                key: 'action',
                                width: '100px',
                                render: (row) => (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => {
                                            setRemoveAttachmentId(row.id);
                                            setRemoveWorkplaceId(null);
                                            setRemoveUserId(null);
                                            setConfirmDeleteOpen(true);
                                        }}
                                        disabled={!perms.DOCUMENT_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                ),
                                align: 'center',
                            },
                        ]}
                        data={data.data.attachments}
                        isFetching={isPending}
                    />
                </Paper>

                <Paper p="md" my="lg" shadow="xs" withBorder>
                    <Title order={5} mb="md">
                        {t('Users')}
                    </Title>
                    <DDataTable
                        highlightOnHover={true}
                        columns={[
                            {
                                name: t('staff no'),
                                key: 'staff_no',
                            },
                            {
                                name: t('Employee Name'),
                                key: 'name',
                            },
                            {
                                name: t('Action'),
                                key: 'action',
                                align: 'center',
                                width: '100px',
                                render: (row) => (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => {
                                            setRemoveUserId(row.id);
                                            setRemoveAttachmentId(null);
                                            setRemoveWorkplaceId(null);
                                            setConfirmDeleteOpen(true);
                                        }}
                                        disabled={!perms.DOCUMENT_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                ),
                            },
                        ]}
                        data={paginatedUsers}
                        isFetching={isPending}
                    />
                    <Pagination
                        total={Math.ceil(document.users.length / pageSize)}
                        value={userPage}
                        onChange={setUserPage}
                        mt="md"
                    />
                </Paper>

                <Paper p="md" my="lg" shadow="xs" withBorder>
                    <Title order={5} mb="md">
                        {t('Workplaces')}
                    </Title>
                    <DDataTable
                        highlightOnHover={true}
                        columns={[
                            {
                                name: t('Workplace name'),
                                key: 'name',
                                width: '450px',
                            },
                            {
                                name: t('Address'),
                                key: 'address',
                            },
                            {
                                name: t('Action'),
                                key: 'action',
                                align: 'center',
                                width: '100px',
                                render: (row) => (
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => {
                                            setRemoveWorkplaceId(row.id);
                                            setRemoveUserId(null);
                                            setRemoveAttachmentId(null);
                                            setConfirmDeleteOpen(true);
                                        }}
                                        disabled={!perms.DOCUMENT_DELETE}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                ),
                            },
                        ]}
                        data={paginatedWorkplaces}
                        isFetching={isPending}
                    />
                    <Pagination
                        total={Math.ceil(document.workplaces.length / pageSize)}
                        value={workplacePage}
                        onChange={setWorkplacePage}
                        mt="md"
                    />
                </Paper>
                <HtmlPreview content={document.content} />
            </Card>

            <UpdateDocumentModal
                isOpened={isShowUpdate}
                onClose={() => setIsShowUpdate(false)}
                document={document}
                onSubmitted={() => {
                    setIsShowUpdate(false);
                }}
            />

            <DConfirmModal
                opened={confirmDeleteOpen}
                title={t('Confirm deletion')}
                message={t('Are you sure you want to remove this item from the document?')}
                onConfirm={handleRemove}
                onCancel={() => setConfirmDeleteOpen(false)}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={isProcessing}
            />
        </>
    );
};

export default DocumentDetailPage;
