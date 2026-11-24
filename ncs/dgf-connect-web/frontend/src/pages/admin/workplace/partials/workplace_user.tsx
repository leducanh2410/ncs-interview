import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Button, Flex, Modal, Paper, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { DUserSelectRemoteMultiple } from '@/components/DUserSelectRemote';
import UserWithAvatar from '@/components/UserWithAvatar';
import { usePermissions } from '@/hooks/usePermission';
import {
    useAddUsersToWorkplaceMutation,
    useGetWorkplaceUsersQuery,
    useRemoveUserFromWorkplaceMutation,
} from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
};
const WorkplaceUserList = ({ workplaceId }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['WORKPLACE_USER_LIST', 'WORKPLACE_USER_ADD', 'WORKPLACE_USER_REMOVE']);
    const [currentUserPage, setCurrentUserPage] = useState<number>(1);
    const { data, isLoading } = useGetWorkplaceUsersQuery(workplaceId, currentUserPage, perms.WORKPLACE_USER_LIST);
    const [isShowAdd, { open: openAdd, close: closeAdd }] = useDisclosure(false);
    const [removeUserId, setRemoveUserId] = useState<number | null>(null);
    const { mutateAsync: addUsers, isPending: isAdding } = useAddUsersToWorkplaceMutation(workplaceId);
    const { mutateAsync: removeUser, isPending: isRemoving } = useRemoveUserFromWorkplaceMutation(workplaceId);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            user_ids: [],
        },
        validate: validatorResolver({
            user_ids: Validator.array().required().minLength(1).label('employee'),
        }),
    });

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        const userIds = values.user_ids as string[];
        try {
            await addUsers(userIds);
            NotificationHelper.showSuccess(t('Successfully added user to workplace'));
            closeAddHandler();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                for (const key of Object.keys(error.data)) {
                    form.setFieldError(key, t(`validation|${error.data[key][0]}`));
                }
            } else if (
                error instanceof ApiError &&
                error.message === ErrorCodeEnum.WORKPLACE_TEST_MEMBERSHIP_MISMATCH.value
            ) {
                NotificationHelper.showError(
                    t('error|Only test employees can join test workplaces, and normal employees only normal ones'),
                );
            } else {
                NotificationHelper.showError(t('error|Could not add user to workplace'));
            }
        }
    };

    const closeAddHandler = () => {
        form.reset();
        closeAdd();
    };

    return (
        <Paper mt="md" p="md">
            <Flex justify="space-between" align="center" mb="16">
                <Title order={3} size={16}>
                    {t('Employee')}
                </Title>
                <Button
                    size="sm"
                    h={32}
                    leftSection={<LuPlus />}
                    onClick={openAdd}
                    disabled={!perms.WORKPLACE_USER_ADD}
                >
                    {t('Add')}
                </Button>
            </Flex>
            <DDataTable
                columns={[
                    { name: 'ID', key: 'id', align: 'center' },
                    {
                        name: t('Staff number'),
                        key: 'staff_no',
                        render: (data) => data.staff_no || '-',
                    },
                    {
                        name: t('Name'),
                        key: 'name',
                        render: (data) => <UserWithAvatar user={data} isShowStaffNo={false} />,
                    },
                    { name: t('Email'), key: 'email' },
                    { name: t('Phone'), key: 'phone' },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData) => (
                            <ActionIcon
                                variant="subtle"
                                color="red"
                                onClick={() => setRemoveUserId(rowData.id)}
                                disabled={!perms.WORKPLACE_USER_REMOVE}
                            >
                                <LuTrash2 size={16} />
                            </ActionIcon>
                        ),
                        width: 100,
                    },
                ]}
                data={data && data.data ? data.data : []}
                isFetching={isLoading}
                showPageSize={false}
                onPageChanged={(page) => setCurrentUserPage(page)}
            />
            <Modal opened={isShowAdd} onClose={closeAddHandler} title={t('Add user')}>
                <form onSubmit={form.onSubmit(onFormSubmit)}>
                    <DUserSelectRemoteMultiple placeholder={t('Select user')} {...form.getInputProps('user_ids')} />
                    <Flex justify="end" mt={24} gap={8}>
                        <Button variant="filled" color="gray" onClick={closeAddHandler}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />} loading={isAdding}>
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
            <DConfirmModal
                opened={removeUserId !== null}
                title={t('Remove user')}
                message={t('Are you sure you want to remove this user from workplace?')}
                onConfirm={async () => {
                    try {
                        await removeUser(removeUserId?.toString() || '');
                        NotificationHelper.showSuccess(t('Successfully removed user from workplace'));
                    } catch (e) {
                        let message = t('error|Could not remove user from workplace');
                        if (e instanceof ApiError) {
                            if (e.message === ErrorCodeEnum.WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT.value) {
                                message = t('error|Could not remove user from workplace: User has shifts');
                            }
                        }
                        NotificationHelper.showError(message);
                    } finally {
                        setRemoveUserId(null);
                    }
                }}
                onCancel={() => setRemoveUserId(null)}
                confirmButtonLabel={t('Remove user')}
                confirmButtonColor="red"
                isProcessing={isRemoving}
            />
        </Paper>
    );
};

export default WorkplaceUserList;
