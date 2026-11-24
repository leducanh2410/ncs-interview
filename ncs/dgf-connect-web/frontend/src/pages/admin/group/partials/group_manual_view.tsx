import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { ActionIcon, Anchor, Badge, Box, Button, Flex, Paper, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { Validator, validatorResolver } from '@/validator';
import { GroupDetail, GroupMember } from '@/types/group';
import { User } from '@/types/models/user';
import RouteConfig from '@/configs/route.config';
import { ApiValidationError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import DUserSelectModal from '@/components/DUserSelectModal';
import { usePermissions } from '@/hooks/usePermission';
import {
    useBatchAttachGroupMembersMutation,
    useDetachGroupMemberMutation,
    useUpdateGroupMutation,
} from '@/pages/admin/group/api';

type Props = {
    group: GroupDetail;
    isFetching: boolean;
    onMembersPageChange: (page: number) => void;
};

const GroupManualView = ({ group, isFetching, onMembersPageChange }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['GROUP_EDIT']);

    const [isEditingName, setIsEditingName] = useState(false);
    const [removeUserId, setRemoveUserId] = useState<number | null>(null);
    const [isAddOpen, { open: openAdd, close: closeAdd }] = useDisclosure(false);

    const updateMutation = useUpdateGroupMutation(group.id);
    const batchAttachMutation = useBatchAttachGroupMembersMutation(group.id);
    const { mutateAsync: detachMember, isPending: isDetaching } = useDetachGroupMemberMutation(group.id);

    const nameForm = useForm<{ name: string }>({
        initialValues: { name: group.name ?? '' },
        validate: validatorResolver({
            name: Validator.string().required().maxLength(255).label('name'),
        }),
    });

    const onSaveName = async (values: { name: string }) => {
        try {
            await updateMutation.mutateAsync({ name: values.name });
            NotificationHelper.showSuccess(t('Successfully updated group'));
            setIsEditingName(false);
        } catch (e) {
            if (e instanceof ApiValidationError) {
                for (const key of Object.keys(e.data)) {
                    nameForm.setFieldError(key, t(`validation|${e.data[key][0]}`));
                }
            } else {
                NotificationHelper.showError(t('error|Could not update group'));
            }
        }
    };

    const onAddMembers = async (users: User[]) => {
        try {
            await batchAttachMutation.mutateAsync(users.map((u) => u.id));
            NotificationHelper.showSuccess(t('Successfully added members'));
            closeAdd();
        } catch (e) {
            if (e instanceof ApiValidationError) {
                NotificationHelper.showError(t('error|Could not add members'));
            } else {
                NotificationHelper.showError(t('error|Could not add members'));
            }
        }
    };

    const onRemoveMember = async () => {
        if (removeUserId === null) return;
        try {
            await detachMember(removeUserId);
            NotificationHelper.showSuccess(t('Successfully removed member'));
        } catch {
            NotificationHelper.showError(t('error|Could not remove member'));
        } finally {
            setRemoveUserId(null);
        }
    };

    return (
        <Box>
            <Paper p="md" radius="md" withBorder mt="md">
                <Flex justify="space-between" align="center" gap="md">
                    {!isEditingName ? (
                        <Box>
                            <Title order={3} size={18}>
                                {group.name}
                            </Title>
                            <Badge color="orange" variant="light" mt={4}>
                                {t('Manual')}
                            </Badge>
                        </Box>
                    ) : (
                        <form onSubmit={nameForm.onSubmit(onSaveName)} style={{ flex: 1 }}>
                            <Flex gap="sm" align="flex-start">
                                <TextInput style={{ flex: 1 }} maxLength={255} {...nameForm.getInputProps('name')} />
                                <Button
                                    type="submit"
                                    leftSection={<LuSave size={14} />}
                                    loading={updateMutation.isPending}
                                >
                                    {t('Save')}
                                </Button>
                                <Button
                                    variant="default"
                                    onClick={() => {
                                        nameForm.setValues({ name: group.name ?? '' });
                                        setIsEditingName(false);
                                    }}
                                >
                                    {t('Cancel')}
                                </Button>
                            </Flex>
                        </form>
                    )}
                    <Flex gap="sm" align="center">
                        <Badge size="lg" variant="light">
                            {t('{{count}} members', { count: group.member_count })}
                        </Badge>
                        {!isEditingName && perms.GROUP_EDIT && (
                            <Button variant="default" onClick={() => setIsEditingName(true)}>
                                {t('Rename')}
                            </Button>
                        )}
                    </Flex>
                </Flex>
            </Paper>
            <Paper p="md" mt="md">
                <Flex justify="space-between" align="center" mb="md">
                    <Title order={3} size={16}>
                        {t('Members')}
                    </Title>
                    <Button size="sm" h={32} leftSection={<LuPlus />} onClick={openAdd} disabled={!perms.GROUP_EDIT}>
                        {t('Add members')}
                    </Button>
                </Flex>
                <DDataTable
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Staff number'),
                            key: 'staff_no',
                            render: (member: GroupMember) => member.staff_no || '-',
                        },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (member: GroupMember) => (
                                <Anchor
                                    component={Link}
                                    href={RouteConfig.ADMIN_USER_DETAIL.path.replace('[id]', member.id.toString())}
                                >
                                    {member.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Workplace'),
                            key: 'current_workplaces',
                            render: (member: GroupMember) =>
                                member.current_workplaces.length
                                    ? member.current_workplaces.map((w) => w.name).join(', ')
                                    : '-',
                        },
                        {
                            name: t('Action'),
                            key: 'action',
                            width: 100,
                            render: (member: GroupMember) => (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => setRemoveUserId(member.id)}
                                    disabled={!perms.GROUP_EDIT}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            ),
                        },
                    ]}
                    data={group.members}
                    isFetching={isFetching}
                    showPageSize={false}
                    onPageChanged={onMembersPageChange}
                />
            </Paper>
            <DUserSelectModal
                isOpen={isAddOpen}
                onClose={closeAdd}
                onConfirm={onAddMembers}
                title={t('Add members')}
                confirmButtonLabel={t('Add members')}
            />
            <DConfirmModal
                opened={removeUserId !== null}
                title={t('Remove member')}
                message={t('Are you sure you want to remove this member from the group?')}
                onConfirm={onRemoveMember}
                onCancel={() => setRemoveUserId(null)}
                confirmButtonLabel={t('Remove member')}
                confirmButtonColor="red"
                isProcessing={isDetaching}
            />
        </Box>
    );
};

export default GroupManualView;
