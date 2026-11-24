import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { useRouter } from 'next/router';
import { Button, Checkbox, Divider, Flex, Grid, Table, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { RoleWithPermissions } from '@/types/models/role';
import RouteConfig from '@/configs/route.config';
import { getPermissionTree } from '@/enums/permission_name.enum';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { useCreateRoleMutation, useUpdateRoleMutation } from '@/pages/admin/role/api';

type RoleFormType = {
    name: string;
    display_name: string;
    description: string | null;
    permissions: Record<string, string[]>;
};

type Props = {
    role: RoleWithPermissions | null;
    isReadOnly?: boolean;
};

const tree = getPermissionTree();
const groupCount: Record<string, number> = {};
Object.keys(tree).forEach((group) => {
    groupCount[group] = Object.keys(tree[group]).reduce((acc, subGroup) => {
        return acc + Object.keys(tree[group][subGroup]).length;
    }, 0);
});

const CreateUpdateForm = ({ role, isReadOnly = false }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateRoleMutation();
    const updateMutation = useUpdateRoleMutation(role?.id.toString() || '');
    const { push } = useRouter();

    const form = useForm<RoleFormType>({
        initialValues: (() => {
            if (!role) {
                return { name: '', display_name: '', description: '', permissions: {} };
            }
            const permissions: Record<string, string[]> = {};
            for (const permission of role.permissions) {
                const group = permission.name.split('.')[0];
                if (!permissions[group]) {
                    permissions[group] = [];
                }
                permissions[group].push(permission.name);
            }
            return {
                name: role.name,
                display_name: role.display_name || '',
                description: role.description,
                permissions,
            };
        })(),
        validate: {
            ...validatorResolver({
                display_name: Validator.string().required().label('display_name'),
            }),
            name: (value) => {
                if (!value) {
                    return t('validation|Please enter {{field}}', { field: t('field|name') });
                }
                return /^[a-z0-9_]+$/.test(value)
                    ? null
                    : t('validation|The {{field}} contains invalid characters', { field: t('field|name') });
            },
        },
    });

    const onSubmit = async (values: RoleFormType) => {
        const permissions: string[] = [];
        for (const groupPermissions of Object.values(values.permissions)) {
            for (const permission of groupPermissions) {
                permissions.push(permission);
            }
        }
        const data = {
            name: values.name,
            display_name: values.display_name,
            description: values.description,
            permissions: permissions,
        };
        try {
            if (role) {
                await updateMutation.mutateAsync(data);
                NotificationHelper.showSuccess(t('Successfully updated role'));
            } else {
                await createMutation.mutateAsync(data);
                NotificationHelper.showSuccess(t('Successfully created role'));
                await push(RouteConfig.ADMIN_ROLE_LIST.path);
            }
        } catch (e) {
            FormHelper.setFormError(form, e);
        }
    };

    return (
        <form onSubmit={form.onSubmit(onSubmit)}>
            <Grid>
                <Grid.Col span={{ base: 12, md: 6, lg: 4 }}>
                    <Flex direction="column" gap="8">
                        <TextInput
                            label={t('Name')}
                            withAsterisk
                            maxLength={255}
                            {...form.getInputProps('name')}
                            readOnly={isReadOnly}
                            description={t('Only letters, numbers, and underscores (_) are accepted.')}
                        />
                        <TextInput
                            label={t('Display name')}
                            withAsterisk
                            maxLength={255}
                            {...form.getInputProps('display_name')}
                            readOnly={isReadOnly}
                        />
                        <TextInput
                            label={t('Description')}
                            maxLength={255}
                            {...form.getInputProps('description')}
                            readOnly={isReadOnly}
                        />
                    </Flex>
                </Grid.Col>
            </Grid>
            <Table withTableBorder withColumnBorders mt="lg">
                <Table.Tbody>
                    {Object.keys(tree).map((group) => {
                        const groupData = tree[group];
                        const checkedCount = form.values.permissions[group]?.length || 0;
                        const isAllChecked = checkedCount === groupCount[group];

                        return (
                            <Table.Tr key={group}>
                                <Table.Td width={240} valign="top" py="md">
                                    <Checkbox
                                        label={t(`role|${group}`)}
                                        checked={isAllChecked}
                                        disabled={isReadOnly}
                                        onChange={(e) => {
                                            if (!e.target.checked) {
                                                form.setFieldValue(`permissions.${group}`, []);
                                            } else {
                                                const tmp = [];
                                                for (const subGroup in groupData) {
                                                    for (const permission of groupData[subGroup]) {
                                                        tmp.push(permission);
                                                    }
                                                }
                                                form.setFieldValue(`permissions.${group}`, tmp);
                                            }
                                        }}
                                    />
                                </Table.Td>
                                <Table.Td p="sm">
                                    <Checkbox.Group key={group} {...form.getInputProps(`permissions.${group}`)}>
                                        {Array.isArray(groupData.general) && (
                                            <Grid p={8}>
                                                {groupData.general.map((permission) => (
                                                    <Grid.Col span={4} p={4} key={permission}>
                                                        <Checkbox
                                                            label={t(`role|${permission}`)}
                                                            value={permission}
                                                            disabled={isReadOnly}
                                                        />
                                                    </Grid.Col>
                                                ))}
                                            </Grid>
                                        )}
                                        {Object.keys(groupData).map((subGroup, index) => {
                                            if (subGroup === 'general') return null;
                                            const permissions = groupData[subGroup];
                                            return (
                                                <React.Fragment key={subGroup}>
                                                    {index !== 0 && <Divider my={8} />}
                                                    <Grid p={8}>
                                                        {permissions.map((permission) => (
                                                            <Grid.Col span={4} p={4} key={permission}>
                                                                <Checkbox
                                                                    label={t(`role|${permission}`)}
                                                                    value={permission}
                                                                    disabled={isReadOnly}
                                                                />
                                                            </Grid.Col>
                                                        ))}
                                                    </Grid>
                                                </React.Fragment>
                                            );
                                        })}
                                    </Checkbox.Group>
                                </Table.Td>
                            </Table.Tr>
                        );
                    })}
                </Table.Tbody>
            </Table>

            <Button
                type="submit"
                leftSection={<LuSave />}
                mt="lg"
                loading={createMutation.isPending || updateMutation.isPending}
                disabled={isReadOnly}
            >
                {t('Save')}
            </Button>
        </form>
    );
};

export default CreateUpdateForm;
