import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import { RoleNameEnum } from '@/enums/role_name.enum';
import NotificationHelper from '@/helpers/notification.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteRoleMutation, useGetRolesQuery } from '@/pages/admin/role/api';

const DISABLED_ROLES = [RoleNameEnum.GLOBAL_ADMIN.value, RoleNameEnum.USER.value];
const DEFAULT_ROLES = [RoleNameEnum.GLOBAL_ADMIN.value, RoleNameEnum.ADMIN.value, RoleNameEnum.USER.value];

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['ROLE_LIST', 'ROLE_CREATE']);
    const { data, isPending } = useGetRolesQuery(router.query, perms.ROLE_LIST);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const deleteMutation = useDeleteRoleMutation();

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Roles')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Roles')}
                </Title>
                <Button
                    component={Link}
                    href={RouteConfig.ADMIN_ROLE_CREATE.path}
                    leftSection={<LuPlus />}
                    onClick={() => {}}
                    disabled={!perms.ROLE_CREATE}
                >
                    {t('Create role')}
                </Button>
            </Flex>
            <Paper mt="md" p="md">
                <DDataTable
                    columns={[
                        {
                            name: t('ID'),
                            key: 'id',
                        },
                        {
                            name: t('Name'),
                            key: 'name',
                        },
                        {
                            name: t('Display name'),
                            key: 'display_name',
                        },
                        {
                            name: t('Description'),
                            key: 'description',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (data) => {
                                const isDisabledEdit = DISABLED_ROLES.includes(data.name);
                                const isDisabledDelete = DEFAULT_ROLES.includes(data.name);

                                return (
                                    <Flex>
                                        <ActionIcon
                                            component={Link}
                                            variant="subtle"
                                            color="blue"
                                            href={RouteConfig.ADMIN_ROLE_DETAIL.path.replace(
                                                '[id]',
                                                data.id.toString(),
                                            )}
                                            disabled={isDisabledEdit}
                                        >
                                            <LuPencilLine size={16} />
                                        </ActionIcon>
                                        <ActionIcon
                                            variant="subtle"
                                            color="red"
                                            onClick={() => setDeleteId(data.id)}
                                            disabled={isDisabledDelete}
                                        >
                                            <LuTrash2 size={16} />
                                        </ActionIcon>
                                    </Flex>
                                );
                            },
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.ROLE_LIST}
                />
            </Paper>
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete role')}
                message={t('Are you sure you want to delete this role? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId);
                        NotificationHelper.showSuccess(t('Successfully deleted role'));
                    } catch (e) {
                        NotificationHelper.showError(t('error|Could not delete role'));
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete role')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
