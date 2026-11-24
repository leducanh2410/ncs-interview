import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge, Box, Flex, SegmentedControl, Title } from '@mantine/core';
import DateHelper from '@/helpers/date.helper';
import DDataTable from '@/components/DDataTable';
import UserWithAvatar from '@/components/UserWithAvatar';
import { useGetUserLockHistoriesQuery } from '@/pages/admin/user/api';

type ActionFilter = 'all' | 'locked' | 'unlocked';

const UserLockHistories = ({ id }: Props) => {
    const { t } = useTranslation();
    const { data, isPending } = useGetUserLockHistoriesQuery(id);
    const [actionFilter, setActionFilter] = useState<ActionFilter>('all');

    const histories = useMemo(() => data?.data || [], [data]);
    const filteredHistories = useMemo(() => {
        if (actionFilter === 'all') {
            return histories;
        }

        return histories.filter((history) => (actionFilter === 'locked' ? history.is_locked : !history.is_locked));
    }, [histories, actionFilter]);

    return (
        <Box>
            <Flex justify="space-between" align="center" mb={16} gap={16}>
                <Title order={3} size={16}>
                    {t('Lock & Unlock History')}
                </Title>
                <SegmentedControl
                    size="xs"
                    value={actionFilter}
                    onChange={(value) => setActionFilter(value as ActionFilter)}
                    data={[
                        { value: 'all', label: t('All') },
                        { value: 'locked', label: t('Lock account') },
                        { value: 'unlocked', label: t('Unlock account') },
                    ]}
                />
            </Flex>
            <DDataTable
                columns={[
                    {
                        name: t('Action by'),
                        key: 'action_by',
                        width: '20%',
                        render: (data) => {
                            return data.action_by ? <UserWithAvatar user={data.action_by} /> : <>—</>;
                        },
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        width: '15%',
                        render: (data) => {
                            return (
                                <Badge size="sm" variant="light" color={data.is_locked ? 'red' : 'teal'}>
                                    {data.is_locked ? t('Lock account') : t('Unlock account')}
                                </Badge>
                            );
                        },
                    },
                    {
                        name: t('Reason'),
                        key: 'reason',
                    },
                    {
                        name: t('Action at'),
                        key: 'action_at',
                        width: '15%',
                        render: (data) => {
                            return <>{DateHelper.parseAndFormat(data.action_at, null, t('FORMAT_TIMESTAMP'))}</>;
                        },
                    },
                ]}
                data={filteredHistories}
                isFetching={isPending}
            />
        </Box>
    );
};

type Props = {
    id: string;
};
export default UserLockHistories;
