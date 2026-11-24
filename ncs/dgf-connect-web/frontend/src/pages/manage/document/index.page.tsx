import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Modal, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import RouteConfig from '@/configs/route.config';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import CreateDocumentModal from '@/pages/manage/document/partials/create_document_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteDocumentMutation, useGetDocumentsQuery } from '@/pages/manage/document/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const [masterData] = useAtom(masterAtom);

    const perms = usePermissions(['DOCUMENT_LIST', 'DOCUMENT_CREATE', 'DOCUMENT_EDIT', 'DOCUMENT_DELETE']);

    const { data, isPending, refetch } = useGetDocumentsQuery(router.query, perms.DOCUMENT_LIST);
    const { mutateAsync: deleteDocument } = useDeleteDocumentMutation();
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const now = DateHelper.now();
    const from = now.subtract(7, 'days');

    const handleDelete = async () => {
        if (deleteId !== null) {
            await deleteDocument(deleteId.toString());
            NotificationHelper.showSuccess(t('Successfully deleted document'));
            setDeleteId(null);
            await refetch();
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Document management')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Document management')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.DOCUMENT_CREATE}>
                    {t('Create document')}
                </Button>
            </Flex>

            <CommonSearchBox
                initialValues={{
                    title: '',
                    dates: [from.toDate(), now.toDate()],
                    workplace_id: '0',
                }}
                elements={[
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Document name'),
                        name: 'title',
                    },
                    {
                        id: 'dates',
                        type: 'date-range',
                        label: t('Create day'),
                        name: 'dates',
                    },
                    {
                        id: 'workplace_id',
                        type: 'select',
                        label: t('Workplace'),
                        name: 'workplace_id',
                        options: masterData.workplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        })),
                        isClearable: false,
                        showAll: true,
                        allValue: '0',
                    },
                ]}
            />

            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        { name: t('ID'), key: 'id' },
                        {
                            name: t('Document name'),
                            key: 'title',
                            render: (row) => (
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('MANAGE_DOCUMENT_DETAIL', { id: row.id })}
                                >
                                    {row.title}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Create day'),
                            key: 'created_at',
                            render: (row) => DateHelper.parseAndFormat(row.created_at),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row) => (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => setDeleteId(row.id)}
                                    disabled={!perms.DOCUMENT_DELETE}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.DOCUMENT_LIST}
                />
            </Paper>

            <Modal opened={deleteId !== null} onClose={() => setDeleteId(null)} title={t('Delete document')} centered>
                <Text>{t('Are you sure you want to delete this document?')}</Text>
                <Flex justify="end" gap="sm" mt="md">
                    <Button variant="default" onClick={() => setDeleteId(null)}>
                        {t('Cancel')}
                    </Button>
                    <Button color="red" onClick={handleDelete}>
                        {t('Delete')}
                    </Button>
                </Flex>
            </Modal>

            <CreateDocumentModal
                isOpened={isShowCreate}
                onClose={() => {
                    closeCreate();
                    refetch();
                }}
                onSubmitted={() => {
                    closeCreate();
                    refetch();
                }}
            />
        </>
    );
};

export default IndexPage;
