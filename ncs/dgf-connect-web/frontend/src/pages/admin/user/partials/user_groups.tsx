import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Anchor, Badge, Box, Loader, Paper, Stack, Text, Title } from '@mantine/core';
import { Group, GroupKind } from '@/types/group';
import RouteConfig from '@/configs/route.config';
import { useGetUserGroupsQuery } from '@/pages/admin/group/api';
import { getGroupDisplayName } from '@/utils/group_display_name';

type Props = {
    id: string;
    isLocked: boolean;
};

type GroupSection = 'system' | 'manual' | 'rule_based';

const SECTION_ORDER: GroupSection[] = ['system', 'manual', 'rule_based'];

const SYSTEM_KINDS: GroupKind[] = ['workplace_current', 'workplace_seniority', 'worktime'];

const KIND_BADGE_COLOR: Record<GroupKind, string> = {
    workplace_current: 'blue',
    workplace_seniority: 'indigo',
    worktime: 'grape',
    manual: 'orange',
    rule_based: 'red',
};

const groupSection = (kind: GroupKind): GroupSection => {
    if (kind === 'manual') return 'manual';
    if (kind === 'rule_based') return 'rule_based';
    return 'system';
};

const useSectionTitle = () => {
    const { t } = useTranslation();
    return (section: GroupSection): string => {
        switch (section) {
            case 'system':
                return t('System groups');
            case 'manual':
                return t('Manual groups');
            case 'rule_based':
                return t('Rule-based groups');
        }
    };
};

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

const UserGroups = ({ id, isLocked }: Props) => {
    const { t } = useTranslation();
    const sectionTitle = useSectionTitle();
    const kindLabel = useKindLabel();
    const { data, isPending } = useGetUserGroupsQuery(id, !isLocked && !!id);

    const grouped = useMemo(() => {
        const buckets: Record<GroupSection, Group[]> = {
            system: [],
            manual: [],
            rule_based: [],
        };
        const groups = data?.data ?? [];
        for (const group of groups) {
            buckets[groupSection(group.kind)].push(group);
        }
        const systemOrder = new Map(SYSTEM_KINDS.map((kind, index) => [kind, index] as const));
        buckets.system.sort((a, b) => {
            const ai = systemOrder.get(a.kind) ?? 999;
            const bi = systemOrder.get(b.kind) ?? 999;
            if (ai !== bi) return ai - bi;
            return a.id - b.id;
        });
        buckets.manual.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
        buckets.rule_based.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''));
        return buckets;
    }, [data]);

    if (isLocked) {
        return (
            <Paper p="md">
                <Title order={3} size={16} mb="md">
                    {t('Groups')}
                </Title>
                <Text c="dimmed">{t('Locked users are not part of any group')}</Text>
            </Paper>
        );
    }

    if (isPending) {
        return (
            <Paper p="md">
                <Loader />
            </Paper>
        );
    }

    const totalCount = data?.data?.length ?? 0;

    if (totalCount === 0) {
        return (
            <Paper p="md">
                <Title order={3} size={16} mb="md">
                    {t('Groups')}
                </Title>
                <Text c="dimmed">{t('This user is not a member of any group yet')}</Text>
            </Paper>
        );
    }

    return (
        <Paper p="md">
            <Title order={3} size={16} mb="md">
                {t('Groups')}
            </Title>
            <Stack gap="lg">
                {SECTION_ORDER.map((section) => {
                    const groups = grouped[section];
                    if (groups.length === 0) return null;
                    return (
                        <Box key={section}>
                            <Text fw={600} mb="xs">
                                {sectionTitle(section)}
                            </Text>
                            <Stack gap="xs">
                                {groups.map((group) => (
                                    <Anchor
                                        key={group.id}
                                        component={Link}
                                        href={RouteConfig.ADMIN_GROUP_DETAIL.path.replace('[id]', group.id.toString())}
                                        underline="hover"
                                    >
                                        <Box
                                            display="flex"
                                            style={{
                                                alignItems: 'center',
                                                gap: 8,
                                            }}
                                        >
                                            <Badge color={KIND_BADGE_COLOR[group.kind]} variant="light">
                                                {kindLabel(group.kind)}
                                            </Badge>
                                            <Text>{getGroupDisplayName(group) || `#${group.id}`}</Text>
                                        </Box>
                                    </Anchor>
                                ))}
                            </Stack>
                        </Box>
                    );
                })}
            </Stack>
        </Paper>
    );
};

export default UserGroups;
