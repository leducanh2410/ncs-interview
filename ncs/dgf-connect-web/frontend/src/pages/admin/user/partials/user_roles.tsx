import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Alert, Box, Button, Checkbox, Flex, Modal, Select, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai/index';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { RoleNameEnum } from '@/enums/role_name.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import { UserRole, useAssignRoleMutation, useGetUserRolesQuery, useRemoveRoleMutation } from '@/pages/admin/user/api';

const UserRoles = ({ isApproved, id }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_LIST', 'USER_ASSIGN_ROLE']);
    const { data, isPending } = useGetUserRolesQuery(id, perms.USER_LIST);
    const { mutateAsync: assignRole, isPending: isAssigning } = useAssignRoleMutation(id);
    const { mutateAsync: removeRole } = useRemoveRoleMutation(id);
    const [opened, { open, close }] = useDisclosure(false);
    const [masterData] = useAtom(masterAtom);

    // Check if user has 'user' role - this role is mutually exclusive with other roles
    const hasUserRole = useMemo(() => {
        return data?.data?.some((role) => role.name === RoleNameEnum.USER.value) ?? false;
    }, [data?.data]);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            role_name: '',
            apply_to_all: true,
            workplace_id: null,
        },
        validate: validatorResolver({
            role_name: Validator.string().required().label(t('field|role')),
            workplace_id: Validator.string()
                .isSelect()
                .requiredIf((values) => !values.apply_to_all)
                .label(t('field|workplace')),
        }),
    });

    const applyToAll = form.values.apply_to_all as boolean;

    const handleClose = () => {
        form.reset();
        close();
    };

    const onRemoveRole = async (role: UserRole) => {
        try {
            await removeRole({
                role_name: role.name,
                workplace_id: role.team_id,
            });
            NotificationHelper.showSuccess(t('Successfully removed role'));
        } catch (e) {
            let message = t('error|Could not remove role');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.USER_CANNOT_ASSIGN_ROLE_SELF.value) {
                    message = t('error|Cannot assign role to yourself');
                } else if (e.message === ErrorCodeEnum.USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN.value) {
                    message = t('error|Cannot assign role to global admin');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        try {
            await assignRole({
                role_name: values.role_name as string,
                workplace_id: values.apply_to_all ? null : (values.workplace_id as number | null),
            });
            NotificationHelper.showSuccess(t('Successfully assigned role'));
        } catch (e) {
            let message = t('error|Could not assign role');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.USER_CANNOT_ASSIGN_ROLE_SELF.value) {
                    message = t('error|Cannot assign role to yourself');
                } else if (e.message === ErrorCodeEnum.USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN.value) {
                    message = t('error|Cannot assign role to global admin');
                } else if (e.message === ErrorCodeEnum.ROLE_USER_CANNOT_HAVE_OTHER_ROLES.value) {
                    message = t('error|User with User role cannot have other roles');
                } else if (e.message === ErrorCodeEnum.ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES.value) {
                    message = t('error|Cannot add User role when user has other roles');
                }
            }
            NotificationHelper.showError(message);
        } finally {
            handleClose();
        }
    };

    const getWorkplaceName = (teamId: number | null) => {
        if (teamId === null) {
            return t('All workplaces');
        }
        const workplace = masterData.workplaces.find((w) => w.id === teamId);
        return workplace ? WorkplaceHelper.formatLabel(workplace, t) : t('Unknown workplace');
    };

    const getRoleDisplayName = (roleName: string) => {
        const role = masterData.roles.find((r) => r.name === roleName);
        return role?.display_name || roleName;
    };

    return (
        <Box>
            <Flex justify="space-between" align="center" mb={16}>
                <Title order={3} size={16}>
                    {t('Roles')}
                </Title>
                <Button
                    size="sm"
                    h={32}
                    leftSection={<LuPlus />}
                    onClick={open}
                    disabled={!isApproved || !perms.USER_ASSIGN_ROLE || hasUserRole}
                >
                    {t('Add Role')}
                </Button>
            </Flex>
            {hasUserRole && (
                <Alert color="blue" mb={16}>
                    {t(
                        'This user has the User role and cannot have additional roles. To add other roles, please remove the User role first.',
                    )}
                </Alert>
            )}
            <DDataTable
                columns={[
                    {
                        name: t('Role'),
                        key: 'name',
                        render: (rowData: UserRole) => getRoleDisplayName(rowData.name),
                    },
                    {
                        name: t('Workplace'),
                        key: 'workplace',
                        render: (rowData: UserRole) => getWorkplaceName(rowData.team_id),
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData: UserRole) => {
                            return (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => onRemoveRole(rowData)}
                                    disabled={!isApproved || !perms.USER_ASSIGN_ROLE}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            );
                        },
                        width: '100px',
                    },
                ]}
                data={data?.data || []}
                isFetching={isPending}
            />
            <Modal opened={opened} onClose={handleClose} title={t('Add Role')}>
                <form onSubmit={form.onSubmit(onFormSubmit)}>
                    <Select
                        label={t('Role')}
                        placeholder={t('Select role')}
                        data={masterData.roles.map((role) => ({
                            value: role.name,
                            label: role.display_name || role.name,
                        }))}
                        searchable
                        mb="md"
                        {...form.getInputProps('role_name')}
                    />
                    <Checkbox
                        label={t('Apply to all workplaces')}
                        mb="md"
                        {...form.getInputProps('apply_to_all', { type: 'checkbox' })}
                    />
                    {!applyToAll && (
                        <Select
                            label={t('Workplace')}
                            placeholder={t('Select workplace')}
                            data={WorkplaceHelper.toSelectOptions(masterData.workplaces, t)}
                            searchable
                            mb="md"
                            {...form.getInputProps('workplace_id')}
                        />
                    )}
                    <Flex justify="end" mt={24} gap={8}>
                        <Button variant="filled" color="gray" onClick={handleClose}>
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            variant="filled"
                            color="teal"
                            leftSection={<LuSave />}
                            loading={isAssigning}
                        >
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </Box>
    );
};

type Props = {
    isApproved: boolean;
    id: string;
};

export default UserRoles;
