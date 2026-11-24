import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { Anchor, Badge, Box, Flex, Group as MGroup, Paper, Title } from '@mantine/core';
import { GroupDetail, GroupMember } from '@/types/group';
import RouteConfig from '@/configs/route.config';
import DDataTable from '@/components/DDataTable';
import { getGroupDisplayName } from '@/utils/group_display_name';

type Props = {
    group: GroupDetail;
    isFetching: boolean;
    onMembersPageChange: (page: number) => void;
};

const GroupSystemView = ({ group, isFetching, onMembersPageChange }: Props) => {
    const { t } = useTranslation();

    return (
        <Box>
            <Paper p="md" radius="md" withBorder mt="md">
                <Flex justify="space-between" align="center">
                    <Box>
                        <Title order={3} size={18}>
                            {getGroupDisplayName(group)}
                        </Title>
                        {group.source_workplace && (
                            <MGroup gap="xs" mt={4}>
                                <Badge color="blue" variant="light">
                                    {group.source_workplace.name}
                                </Badge>
                                {group.source_tier && (
                                    <Badge color="gray" variant="light">
                                        {group.source_tier}
                                    </Badge>
                                )}
                            </MGroup>
                        )}
                        {!group.source_workplace && group.source_tier && (
                            <Badge color="grape" variant="light" mt={4}>
                                {group.source_tier}
                            </Badge>
                        )}
                    </Box>
                    <Badge size="lg" variant="light">
                        {t('{{count}} members', { count: group.member_count })}
                    </Badge>
                </Flex>
            </Paper>
            <Paper p="md" mt="md">
                <Title order={3} size={16} mb="md">
                    {t('Members')}
                </Title>
                <DDataTable
                    columns={[
                        { name: 'ID', key: 'id', align: 'center', width: 80 },
                        {
                            name: t('Staff number'),
                            key: 'staff_no',
                            render: (member: GroupMember) => member.staff_no || '-',
                        },
                        {
                            name: t('Name'),
                            key: 'name',
                            render: (member: GroupMember) => (
                                <Anchor
                                    component={Link}
                                    href={RouteConfig.ADMIN_USER_DETAIL.path.replace('[id]', member.id.toString())}
                                >
                                    {member.name}
                                </Anchor>
                            ),
                        },
                        {
                            name: t('Workplace'),
                            key: 'current_workplaces',
                            render: (member: GroupMember) =>
                                member.current_workplaces.length
                                    ? member.current_workplaces.map((w) => w.name).join(', ')
                                    : '-',
                        },
                    ]}
                    data={group.members}
                    isFetching={isFetching}
                    showPageSize={false}
                    onPageChanged={onMembersPageChange}
                />
            </Paper>
        </Box>
    );
};

export default GroupSystemView;
