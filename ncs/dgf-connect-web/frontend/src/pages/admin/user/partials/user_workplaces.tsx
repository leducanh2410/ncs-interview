import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { ActionIcon, Anchor, Box, Button, Flex, Modal, MultiSelect, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai/index';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import DDataTable from '@/components/DDataTable';
import { masterAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import {
    useAddWorkplacesMutation,
    useDeleteWorkplaceMutation,
    useGetUserWorkplacesQuery,
} from '@/pages/admin/user/api';

const UserWorkplaces = ({ id }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_WORKPLACE_LIST', 'USER_WORKPLACE_ADD', 'USER_WORKPLACE_REMOVE']);
    const { data, isPending } = useGetUserWorkplacesQuery(id, perms.USER_WORKPLACE_LIST);
    const { mutateAsync: deleteWorkplace } = useDeleteWorkplaceMutation(id);
    const { mutateAsync: addToWorkplaces, isPending: isAdding } = useAddWorkplacesMutation(id);
    const [opened, { open, close }] = useDisclosure(false);
    const [masterData] = useAtom(masterAtom);

    const onDeleteWorkplace = async (id: number) => {
        try {
            await deleteWorkplace(id.toString());
            NotificationHelper.showSuccess(t('Successfully removed user from workplace'));
        } catch (e) {
            let message = t('error|Could not remove user from workplace');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT.value) {
                    message = t('error|Could not remove user from workplace: User has shifts');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            workplace_ids: [],
        },
        validate: validatorResolver({
            workplace_ids: Validator.array().required().minLength(1).label('workplace'),
        }),
    });

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        try {
            await addToWorkplaces(values.workplace_ids as string[]);
            NotificationHelper.showSuccess(t('Successfully added user to workplace'));
        } catch (e) {
            let message = t('error|Could not add user to workplace');
            if (e instanceof ApiError && e.message === ErrorCodeEnum.WORKPLACE_TEST_MEMBERSHIP_MISMATCH.value) {
                message = t(
                    'error|Only test employees can join test workplaces, and normal employees only normal ones',
                );
            }
            NotificationHelper.showError(message);
        } finally {
            form.reset();
            close();
        }
    };

    return (
        <Box>
            <Flex justify="space-between" align="center" mb={16}>
                <Title order={3} size={16}>
                    {t('Workplaces')}
                </Title>
                <Button size="sm" h={32} leftSection={<LuPlus />} onClick={open} disabled={!perms.USER_WORKPLACE_ADD}>
                    {t('Add')}
                </Button>
            </Flex>
            <DDataTable
                columns={[
                    {
                        name: 'ID',
                        key: 'id',
                        width: '100px',
                        align: 'center',
                    },
                    {
                        name: t('Workplace name'),
                        key: 'name',
                        render: (data) => (
                            <Anchor
                                component={Link}
                                href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: data.id })}
                            >
                                {data.name}
                            </Anchor>
                        ),
                    },
                    {
                        name: t('Address'),
                        key: 'address',
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData) => {
                            return (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => onDeleteWorkplace(rowData.id)}
                                    disabled={!perms.USER_WORKPLACE_REMOVE}
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
            <Modal opened={opened} onClose={close} title={t('Add workplace')}>
                <form onSubmit={form.onSubmit(onFormSubmit)}>
                    <MultiSelect
                        placeholder={t('Select workplace')}
                        data={WorkplaceHelper.toSelectOptions(masterData.workplaces, t)}
                        searchable
                        {...form.getInputProps('workplace_ids')}
                    />
                    <Flex justify="end" mt={24} gap={8}>
                        <Button variant="filled" color="gray" onClick={close}>
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />} loading={isAdding}>
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </Box>
    );
};

type Props = {
    id: string;
};

export default UserWorkplaces;
