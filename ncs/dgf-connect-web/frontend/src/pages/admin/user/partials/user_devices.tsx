import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Box, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteDeviceMutation, useGetUserDevicesQuery } from '@/pages/admin/user/api';

const UserDevices = ({ isApproved, id }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_DEVICE_LIST', 'USER_DEVICE_DELETE']);
    const { data, isPending } = useGetUserDevicesQuery(id, perms.USER_DEVICE_LIST);
    const { mutateAsync: deleteDevice } = useDeleteDeviceMutation(id);
    const [isShowDelete, { open: openDelete, close: closeDelete }] = useDisclosure(false);
    const [currentDeviceId, setCurrentDeviceId] = React.useState<number | null>(null);

    return (
        <Box>
            <Title order={3} size={16} mb={16}>
                {t('Device')}
            </Title>
            <DDataTable
                columns={[
                    {
                        name: 'ID',
                        key: 'id',
                        width: '100px',
                        align: 'center',
                    },
                    {
                        name: t('Platform'),
                        key: 'platform',
                    },
                    {
                        name: t('Device name'),
                        key: 'name',
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData) => {
                            return (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => {
                                        setCurrentDeviceId(rowData.id);
                                        openDelete();
                                    }}
                                    disabled={!isApproved || !perms.USER_DEVICE_DELETE}
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
            <DConfirmModal
                opened={isShowDelete}
                title={t('Delete device')}
                message={t('Are you sure you want to delete this device?')}
                confirmButtonLabel={t('Delete device')}
                confirmButtonColor="red"
                onConfirm={async () => {
                    if (!currentDeviceId || !isApproved) {
                        return;
                    }
                    try {
                        await deleteDevice(currentDeviceId.toString());
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted device'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete device'),
                        });
                    } finally {
                        closeDelete();
                    }
                }}
                onCancel={closeDelete}
            />
        </Box>
    );
};

type Props = {
    isApproved: boolean;
    id: string;
};

export default UserDevices;
