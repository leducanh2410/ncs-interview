import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Flex, Loader, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import { RoleNameEnum } from '@/enums/role_name.enum';
import CreateUpdateForm from '@/pages/admin/role/partials/create_update_form';
import { useGetRoleQuery } from '@/pages/admin/role/api';

const CreatePage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const { data, isFetching } = useGetRoleQuery(id);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_ROLE_LIST.path}>
                    {t('Role list')}
                </Anchor>
                <Text>{t('Update role')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Update role')}
                </Title>
            </Flex>

            <Paper p="md" mt="md" radius="md" withBorder>
                {isFetching && <Loader />}
                {data && data.data && (
                    <CreateUpdateForm
                        key={data.data.id}
                        role={data.data}
                        isReadOnly={data.data.name === RoleNameEnum.GLOBAL_ADMIN.value}
                    />
                )}
            </Paper>
        </>
    );
};

export default CreatePage;
