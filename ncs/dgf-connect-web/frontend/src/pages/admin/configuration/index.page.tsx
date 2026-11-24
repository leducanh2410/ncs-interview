import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine } from 'react-icons/lu';
import Link from 'next/link';
import { Anchor, Breadcrumbs, Button, Flex, Loader, Modal, Paper, Text, Title } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import RouteConfig from '@/configs/route.config';
import DDataTable from '@/components/DDataTable';
import ConfigurationForm from '@/pages/admin/configuration/partials/configuration_form';
import { usePermissions } from '@/hooks/usePermission';
import { useGetConfigsQuery } from '@/pages/admin/configuration/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions(['CONFIG_LIST', 'CONFIG_EDIT']);
    const { data, isPending } = useGetConfigsQuery(perms.CONFIG_LIST);
    const [isShowUpdate, { open: openUpdate, close: closeUpdate }] = useDisclosure(false);

    if (isPending) {
        return (
            <Flex w="100%" h="200" align="center" justify="center">
                <Loader />
            </Flex>
        );
    }

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Configuration')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Configuration')}
                </Title>
                <Button h={32} leftSection={<LuPencilLine />} onClick={openUpdate} disabled={!perms.CONFIG_EDIT}>
                    {t('Edit')}
                </Button>
            </Flex>
            <Paper p="md">
                <DDataTable
                    columns={[
                        {
                            name: t('Configuration name'),
                            key: 'name',
                            width: '60%',
                        },
                        {
                            name: t('Value'),
                            key: 'value',
                            width: '40%',
                            render: (row) =>
                                /^https?:\/\//i.test(row.value ?? '') ? (
                                    <Anchor
                                        href={row.value ?? undefined}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{ overflowWrap: 'anywhere' }}
                                    >
                                        {row.value}
                                    </Anchor>
                                ) : (
                                    <Text style={{ overflowWrap: 'anywhere' }}>{row.value}</Text>
                                ),
                        },
                    ]}
                    data={[
                        {
                            name: t('Max shifts per day'),
                            value: data?.data?.max_shift_per_day,
                        },
                        {
                            name: t('Max hours per week'),
                            value: data?.data?.max_hour_per_week,
                        },
                        {
                            name: t('Salary per hour'),
                            value: data?.data?.salary_per_hour,
                        },
                        {
                            name: t('Yamato login URL'),
                            value: data?.data?.login_url,
                        },
                    ]}
                    isFetching={isPending}
                />
            </Paper>
            <Modal opened={isShowUpdate} onClose={closeUpdate} title={t('Edit configuration')}>
                <ConfigurationForm config={data?.data} onClose={closeUpdate} />
            </Modal>
        </>
    );
};
export default IndexPage;
