import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Anchor, Breadcrumbs, Flex, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import CreateUpdateForm from '@/pages/admin/role/partials/create_update_form';

const CreatePage = () => {
    const { t } = useTranslation();

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_ROLE_LIST.path}>
                    {t('Role list')}
                </Anchor>
                <Text>{t('Create role')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Create role')}
                </Title>
            </Flex>

            <Paper p="md" mt="md" radius="md" withBorder>
                <CreateUpdateForm role={null} />
            </Paper>
        </>
    );
};

export default CreatePage;
