import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuPlus } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Breadcrumbs, Button, Flex, Paper, Text, Title } from '@mantine/core';
import { Group, GroupKind } from '@/types/group';
import RouteConfig from '@/configs/route.config';
import CommonSearchBox from '@/components/CommonSearchBox';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useGetGroupsQuery } from '@/pages/admin/group/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

const useKindLabel = () => {
    const { t } = useTranslation();
    return (kind: GroupKind): string => {
        switch (kind) {
            case 'workplace_current':
                return t('Current workplace');
            case 'workplace_seniority':
                return t('Workplace seniority');
            case 'worktime':
                return t('Worktime');
            case 'manual':
                return t('Manual');
            case 'rule_based':
                return t('Rule based');
            default:
                return kind;
        }
    };
};

const KIND_OPTIONS: GroupKind[] = ['workplace_current', 'workplace_seniority', 'worktime', 'manual', 'rule_based'];

const IndexPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const perms = usePermissions(['GROUP_VIEW', 'GROUP_EDIT']);
    const { data, isPending } = useGetGroupsQuery(router.query, perms.GROUP_VIEW);
    const kindLabel = useKindLabel();

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Groups')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Groups')}
                </Title>
                <Button
                    component={Link}
                    href={RouteConfig.ADMIN_GROUP_CREATE.path}
                    leftSection={<LuPlus />}
                    disabled={!perms.GROUP_EDIT}
                >
                    {t('Create group')}
                </Button>
            </Flex>
            <CommonSearchBox
                initialValues={{
                    search: '',
                    kind: [],
                }}
                elements={[
                    {
                        id: 'search',
                        type: 'input-text',
                        label: t('Search'),
                        name: 'search',
                    },
                    {
                        id: 'kind',
                        type: 'select',
                        label: t('Kind'),
                        name: 'kind',
                        options: KIND_OPTIONS.map((kind) => ({ value: kind, label: kindLabel(kind) })),
                        isMulti: true,
                        isClearable: true,
                    },
                ]}
            />
            <Paper p="md" mt="md">
                <DDataTable
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Kind'),
                            key: 'kind',
                            render: (group: Group) => kindLabel(group.kind),
                            width: 180,
                        },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (group: Group) => (
                                <Anchor
                                    component={Link}
                                    href={RouteConfig.ADMIN_GROUP_DETAIL.path.replace('[id]', group.id.toString())}
                                >
                                    {getGroupDisplayName(group) || '-'}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Source'),
                            key: 'source',
                            render: (group: Group) => {
                                if (group.source_workplace) return group.source_workplace.name;
                                if (group.source_tier) return group.source_tier;
                                return '-';
                            },
                        },
                        {
                            name: t('Members'),
                            key: 'member_count',
                            align: 'right',
                            width: 120,
                        },
                    ]}
                    data={data?.data || []}
                    isFetching={isPending}
                    noPermission={!perms.GROUP_VIEW}
                />
            </Paper>
        </>
    );
};

export default IndexPage;
