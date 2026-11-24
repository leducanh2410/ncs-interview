import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Anchor, Breadcrumbs, Flex, Loader, Paper, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import SupportTypeConfigSection from '@/pages/admin/configuration/partials/support_type_config';
import { usePermissions } from '@/hooks/usePermission';
import { useSupportTypeConfigsQuery } from '@/pages/admin/chat/support/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const perms = usePermissions(['CHAT_ASSIGN']);
    const { data: supportTypeConfigs, isPending } = useSupportTypeConfigsQuery(perms.CHAT_ASSIGN);

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
                <Anchor component={Link} href={RouteConfig.ADMIN_CHAT_SUPPORT_LIST.path}>
                    {t('Support chats')}
                </Anchor>
                <Text>{t('Support type admin assignment')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Support type admin assignment')}
            </Title>
            <Paper p="md" maw={640}>
                <SupportTypeConfigSection
                    configs={supportTypeConfigs}
                    isLoading={isPending}
                    canEdit={perms.CHAT_ASSIGN}
                />
            </Paper>
        </>
    );
};

export default IndexPage;
