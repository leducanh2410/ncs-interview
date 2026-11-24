import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { ActionIcon, Anchor, Badge, Breadcrumbs, Button, Flex, Modal, Paper, Switch, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { ChatBannedKeyword } from '@/types/models/chat';
import RouteConfig from '@/configs/route.config';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { useDeleteBannedKeywordMutation, useGetBannedKeywordsQuery, useUpdateBannedKeywordMutation } from './api';
import BannedKeywordForm from './partials/banned_keyword_form';
import { usePermissions } from '@/hooks/usePermission';

const BannedKeywordsPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions(['CONFIG_LIST', 'CONFIG_EDIT']);
    const { data, isPending } = useGetBannedKeywordsQuery(perms.CONFIG_LIST);
    const deleteMutation = useDeleteBannedKeywordMutation();
    const updateMutation = useUpdateBannedKeywordMutation();

    const [editing, setEditing] = useState<ChatBannedKeyword | null>(null);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [formOpened, { open: openForm, close: closeForm }] = useDisclosure(false);

    const handleEdit = (kw: ChatBannedKeyword) => {
        setEditing(kw);
        openForm();
    };

    const handleAdd = () => {
        setEditing(null);
        openForm();
    };

    const handleToggleActive = async (kw: ChatBannedKeyword) => {
        try {
            await updateMutation.mutateAsync({ id: kw.id, data: { is_active: !kw.is_active } });
        } catch {
            notifications.show({ message: t('error|Could not update keyword'), color: 'red' });
        }
    };

    const keywords = data?.data ?? [];

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Banned chat keywords')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Banned chat keywords')}
                </Title>
                <Button leftSection={<LuPlus size={16} />} onClick={handleAdd} disabled={!perms.CONFIG_EDIT}>
                    {t('Add keyword')}
                </Button>
            </Flex>
            <Paper mt="md" p="md">
                <DDataTable
                    columns={[
                        { name: t('Keyword'), key: 'keyword' },
                        {
                            name: t('Language'),
                            key: 'locale',
                            render: (row: ChatBannedKeyword) => row.locale ?? t('All'),
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (row: ChatBannedKeyword) => (
                                <Badge color={row.action === 'block' ? 'red' : 'orange'}>
                                    {row.action === 'block' ? t('Block') : t('Mask')}
                                </Badge>
                            ),
                        },
                        {
                            name: t('Active'),
                            key: 'is_active',
                            render: (row: ChatBannedKeyword) => (
                                <Switch
                                    checked={row.is_active}
                                    onChange={() => handleToggleActive(row)}
                                    disabled={!perms.CONFIG_EDIT}
                                />
                            ),
                        },
                        {
                            name: '',
                            key: 'actions',
                            render: (row: ChatBannedKeyword) => (
                                <Flex gap="xs">
                                    <ActionIcon
                                        variant="subtle"
                                        color="blue"
                                        onClick={() => handleEdit(row)}
                                        disabled={!perms.CONFIG_EDIT}
                                    >
                                        <LuPencilLine size={16} />
                                    </ActionIcon>
                                    <ActionIcon
                                        variant="subtle"
                                        color="red"
                                        onClick={() => setDeleteId(row.id)}
                                        disabled={!perms.CONFIG_EDIT}
                                    >
                                        <LuTrash2 size={16} />
                                    </ActionIcon>
                                </Flex>
                            ),
                            width: '80px',
                        },
                    ]}
                    data={keywords}
                    isFetching={isPending}
                    noPermission={!perms.CONFIG_LIST}
                />
            </Paper>

            <Modal
                opened={formOpened}
                onClose={closeForm}
                title={editing ? t('Edit banned keyword') : t('Add banned keyword')}
            >
                <BannedKeywordForm keyword={editing} onClose={closeForm} />
            </Modal>

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete banned keyword')}
                message={t('Are you sure you want to delete this keyword?')}
                onConfirm={async () => {
                    if (deleteId === null) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId);
                        notifications.show({ message: t('Banned keyword deleted'), color: 'teal' });
                    } catch {
                        notifications.show({ message: t('error|Could not delete keyword'), color: 'red' });
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

export default BannedKeywordsPage;
