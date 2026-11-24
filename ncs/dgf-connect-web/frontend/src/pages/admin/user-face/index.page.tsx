import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuRefreshCw, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Breadcrumbs, Button, Flex, Group, Paper, Text, Title, Tooltip } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import CreateUserFaceModal from '@/pages/admin/user-face/partials/create_user_face_modal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDeleteUserFaceMutation,
    useGetUserFacesQuery,
    useUpdateUserFaceMutation,
} from '@/pages/admin/user-face/api';

const AdminUserFaceIndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['USER_FACE_LIST', 'USER_FACE_CREATE', 'USER_FACE_UPDATE', 'USER_FACE_DELETE']);
    const { data, isPending } = useGetUserFacesQuery(router.query);

    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);
    const [isShowUpdate, { open: openUpdate, close: closeUpdate }] = useDisclosure(false);
    const [isShowDelete, { open: openDelete, close: closeDelete }] = useDisclosure(false);
    const [selectedUserId, setSelectedUserId] = useState<number | null>(null);

    const updateMutation = useUpdateUserFaceMutation();
    const deleteMutation = useDeleteUserFaceMutation();

    const handleUpdate = async () => {
        if (!selectedUserId) return;
        try {
            await updateMutation.mutateAsync(selectedUserId);
            NotificationHelper.showSuccess(t('Successfully updated user face'));
            closeUpdate();
        } catch (e) {
            let message = t('Could not update user face');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.USER_FACE_NO_APPROVED_PORTRAIT.value) {
                    message = t('error|No approved portrait image found');
                } else if (e.message === ErrorCodeEnum.USER_FACE_REMOVAL_FAILED.value) {
                    message = t('error|Face removal failed');
                } else if (e.message === ErrorCodeEnum.USER_FACE_UPDATE_FAILED.value) {
                    message = t('error|Face update failed');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    const handleDelete = async () => {
        if (!selectedUserId) return;
        try {
            await deleteMutation.mutateAsync(selectedUserId);
            NotificationHelper.showSuccess(t('Successfully deleted user face'));
            closeDelete();
        } catch (e) {
            let message = t('Could not delete user face');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.USER_FACE_REMOVAL_FAILED.value) {
                    message = t('error|Face removal failed');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('User Face List')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('User Face List')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.USER_FACE_CREATE}>
                    {t('Create User Face')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    name: '',
                    staff_no: '',
                }}
                elements={[
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Name'),
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
            <Paper p="md" mt="md">
                <DDataTable
                    tableKey={'admin-user-face-list'}
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('User Detail'),
                            key: 'user',
                            render: (data) =>
                                data.user ? <UserWithAvatar user={data.user} isShowStaffNo={true} /> : '-',
                        },
                        {
                            name: t('Face ID'),
                            key: 'vector_id',
                        },
                        {
                            name: t('Provider'),
                            key: 'provider',
                        },
                        {
                            name: t('Updated At'),
                            key: 'updated_at',
                            render: (data) =>
                                DateHelper.parseAndFormat(data.updated_at, null, t('FORMAT_DATE_WITH_TIME')),
                        },
                        {
                            name: t('Action'),
                            key: 'actions',
                            align: 'center',
                            width: 120,
                            render: (data) => (
                                <Group gap="xs" justify="center">
                                    <Tooltip label={t('Update')}>
                                        <ActionIcon
                                            variant="light"
                                            color="blue"
                                            onClick={() => {
                                                setSelectedUserId(data.user_id);
                                                openUpdate();
                                            }}
                                            disabled={!perms.USER_FACE_UPDATE}
                                        >
                                            <LuRefreshCw size={16} />
                                        </ActionIcon>
                                    </Tooltip>
                                    <Tooltip label={t('Delete')}>
                                        <ActionIcon
                                            variant="light"
                                            color="red"
                                            onClick={() => {
                                                setSelectedUserId(data.user_id);
                                                openDelete();
                                            }}
                                            disabled={!perms.USER_FACE_DELETE}
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
            <CreateUserFaceModal isOpened={isShowCreate} onClose={closeCreate} />
            <DConfirmModal
                opened={isShowUpdate}
                title={t('Update')}
                message={t('Are you sure you want to update this user face?')}
                onConfirm={handleUpdate}
                onCancel={closeUpdate}
                confirmButtonColor="blue"
                isProcessing={updateMutation.isPending}
            />
            <DConfirmModal
                opened={isShowDelete}
                title={t('Delete')}
                message={t('Are you sure you want to delete this user face?')}
                onConfirm={handleDelete}
                onCancel={closeDelete}
                confirmButtonColor="red"
                isProcessing={deleteMutation.isPending}
            />
        </>
    );
};

export default AdminUserFaceIndexPage;
