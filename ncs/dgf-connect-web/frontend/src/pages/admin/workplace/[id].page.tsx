import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Flex, Text, Title } from '@mantine/core';
import RouteConfig from '@/configs/route.config';
import WorkplaceInfo from '@/pages/admin/workplace/partials/workplace_info';
import WorkplaceInstructionList from '@/pages/admin/workplace/partials/workplace_instruction';
import WorkplacePositionList from '@/pages/admin/workplace/partials/workplace_position';
import WorkplaceShiftList from '@/pages/admin/workplace/partials/workplace_shift';
import WorkplaceUserList from '@/pages/admin/workplace/partials/workplace_user';
import { useGetWorkplaceDetailQuery } from '@/pages/admin/workplace/api';

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const workplaceId = router.query.id as string;
    const { data, isFetching } = useGetWorkplaceDetailQuery(workplaceId);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_WORKPLACE_LIST.path}>
                    {t('Manage workplaces')}
                </Anchor>
                <Text>{t('Workplace Detail')}</Text>
            </Breadcrumbs>
            <Title order={4} lh="48px" my="md">
                {t('Workplace Detail')}
            </Title>
            <WorkplaceInfo id={workplaceId} workplace={data?.data} isFetching={isFetching} />
            <Flex justify="space-between" align="flex-start" mt="md" gap="16">
                <WorkplacePositionList workplaceId={workplaceId} />
                <WorkplaceShiftList workplaceId={workplaceId} />
            </Flex>
            <WorkplaceInstructionList workplaceId={workplaceId} />
            <WorkplaceUserList workplaceId={workplaceId} />
        </>
    );
};

export default IndexPage;
