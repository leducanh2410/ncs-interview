import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { ActionIcon, Anchor, Badge, Breadcrumbs, Button, Flex, Modal, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import RouteConfig from '@/configs/route.config';
import RouteHelper from '@/helpers/route.helper';
import CommonSearchBox from '@/components/CommonSearchBox';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import WorkplaceForm from '@/pages/admin/workplace/partials/workplace_form';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteWorkplaceMutation, useGetWorkplacesQuery } from '@/pages/admin/workplace/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['WORKPLACE_LIST', 'WORKPLACE_CREATE', 'WORKPLACE_EDIT', 'WORKPLACE_DELETE']);
    const { data, isPending } = useGetWorkplacesQuery(router.query, perms.WORKPLACE_LIST);
    const { mutateAsync: deleteWorkplace } = useDeleteWorkplaceMutation();
    const [deleteId, setDeleteId] = React.useState<string | null>(null);
    const [isShowCreate, { open: openCreate, close: closeCreate }] = useDisclosure(false);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Manage workplaces')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Manage workplaces')}
                </Title>
                <Button leftSection={<LuPlus />} onClick={openCreate} disabled={!perms.WORKPLACE_CREATE}>
                    {t('Create Workplace')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{ id: '', name: '' }}
                elements={[
                    {
                        id: 'id',
                        type: 'input-text',
                        label: t('ID'),
                        name: 'id',
                    },
                    {
                        id: 'name',
                        type: 'input-text',
                        label: t('Name'),
                        name: 'name',
                    },
                ]}
            />
            <Paper mt="md" p="md">
                <DDataTable
                    columns={[
                        { name: 'ID', key: 'id', align: 'center' },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (data) => (
                                <Flex align="center" gap={8}>
                                    <Anchor
                                        component={Link}
                                        href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', { id: data.id })}
                                    >
                                        {data.name}
                                    </Anchor>
                                    {data.is_test && (
                                        <Badge color="orange" variant="light" size="sm">
                                            {t('TEST')}
                                        </Badge>
                                    )}
                                </Flex>
                            ),
                        },
                        {
                            name: t('Coordinate'),
                            key: 'coordinate',
                            render: (data) => `${data.latitude}, ${data.longitude}`,
                        },
                        { name: t('Address'), key: 'address' },
                        {
                            name: t('Action'),
                            key: 'action',
                            render: (data) => (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => setDeleteId(data.id.toString())}
                                    disabled={!perms.WORKPLACE_DELETE}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            ),
                            width: '100px',
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.WORKPLACE_LIST}
                />
            </Paper>
            <Modal size="lg" opened={isShowCreate} onClose={closeCreate} title={t('Create Workplace')}>
                <WorkplaceForm
                    id={null}
                    workplace={null}
                    isFetching={false}
                    onCancel={closeCreate}
                    onDone={closeCreate}
                />
            </Modal>
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete workplace')}
                message={t('Are you sure you want to delete this workplace? This action cannot be undone.')}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteWorkplace(deleteId);
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted workplace'),
                        });
                    } catch (e) {
                        // TODO: Handle custom error message here
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete workplace'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
                onCancel={() => setDeleteId(null)}
                confirmButtonLabel={t('Delete workplace')}
                confirmButtonColor="red"
            />
        </>
    );
};

export default IndexPage;
