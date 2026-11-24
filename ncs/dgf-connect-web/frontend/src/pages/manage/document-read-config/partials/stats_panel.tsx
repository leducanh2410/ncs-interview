import React from 'react';
import { useTranslation } from 'react-i18next';
import { Flex, Group, Loader, Paper, Progress, Stack, Text } from '@mantine/core';
import CommonHelper from '@/helpers/common.helper';
import { useDocumentReadConfigStatsQuery } from '@/pages/manage/document-read-config/api';

type Props = {
    configId: number | null;
    opened: boolean;
};

const StatsPanel = ({ configId, opened }: Props) => {
    const { t } = useTranslation();
    const { data, isPending } = useDocumentReadConfigStatsQuery(configId, opened && !!configId);

    if (!opened) return null;

    if (isPending) {
        return (
            <Paper p="md" withBorder>
                <Flex justify="center" py="md">
                    <Loader size="sm" />
                </Flex>
            </Paper>
        );
    }

    const stats = data?.data;
    const eligible = stats?.eligible_count ?? 0;
    const confirmed = stats?.confirmed_count ?? 0;
    const pending = stats?.pending_count ?? 0;
    const rate = stats?.confirmation_rate ?? 0;
    const percent = Math.round(rate * 100);

    return (
        <Paper p="md" withBorder>
            <Stack gap="xs">
                <Text fw={600} size="sm">
                    {t('Confirmation Rate')}
                </Text>
                <Progress value={percent} size="lg" color={percent === 100 ? 'teal' : 'blue'} />
                <Group justify="space-between">
                    <Text size="sm" c="dimmed">
                        {t('{{percent}}%', { percent })}
                    </Text>
                </Group>
                <Group gap="xl" mt={4}>
                    <Flex direction="column" align="center">
                        <Text fw={600} size="lg">
                            {CommonHelper.formatNumber(eligible)}
                        </Text>
                        <Text size="xs" c="dimmed">
                            {t('Eligible')}
                        </Text>
                    </Flex>
                    <Flex direction="column" align="center">
                        <Text fw={600} size="lg" c="teal">
                            {CommonHelper.formatNumber(confirmed)}
                        </Text>
                        <Text size="xs" c="dimmed">
                            {t('Confirmed')}
                        </Text>
                    </Flex>
                    <Flex direction="column" align="center">
                        <Text fw={600} size="lg" c="orange">
                            {CommonHelper.formatNumber(pending)}
                        </Text>
                        <Text size="xs" c="dimmed">
                            {t('Pending')}
                        </Text>
                    </Flex>
                </Group>
            </Stack>
        </Paper>
    );
};

export default StatsPanel;
