import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowRight, LuTrendingUp } from 'react-icons/lu';
import Link from 'next/link';
import { Button, Group, Paper, Stack, Text } from '@mantine/core';

const ForecastEntryCard: React.FC = () => {
    const { t } = useTranslation();

    return (
        <Paper p="md">
            <Stack gap="sm">
                <Group gap="xs">
                    <LuTrendingUp size={18} />
                    <Text fw={600}>{t('Forecast an incoming order')}</Text>
                </Group>
                <Text size="sm" c="dimmed">
                    {t('Estimate available staff for a date and shift slot before you commit to a new order.')}
                </Text>
                <Button
                    component={Link}
                    href="/admin/forecast"
                    rightSection={<LuArrowRight size={14} />}
                    variant="light"
                >
                    {t('Open forecast')}
                </Button>
            </Stack>
        </Paper>
    );
};

export default ForecastEntryCard;
