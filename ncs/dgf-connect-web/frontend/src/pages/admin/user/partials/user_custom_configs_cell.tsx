import React from 'react';
import { useTranslation } from 'react-i18next';
import { Stack, Text } from '@mantine/core';
import { UserCustomConfig, UserCustomConfigKey } from '@/types/models/config';
import DateHelper from '@/helpers/date.helper';

const UserCustomConfigsCell = ({ configs }: Props) => {
    const { t } = useTranslation();

    if (!configs?.length) {
        return <Text size="xs">-</Text>;
    }

    const labels: Record<UserCustomConfigKey, string> = {
        max_shift_per_day: t('Max shifts per day'),
        max_hour_per_week: t('Max hours per week'),
    };

    return (
        <Stack gap={4}>
            {configs.map((config) => (
                <div key={config.key}>
                    <Text size="xs" fw={500} lh={1.4}>
                        {t('{{name}}: {{value}} (default: {{default}})', {
                            name: labels[config.key],
                            value: config.value,
                            default: config.default_value,
                        })}
                    </Text>
                    <Text fz={11} c="dimmed" lh={1.4} style={{ whiteSpace: 'nowrap' }}>
                        {`${DateHelper.parseAndFormat(config.start_date)} ~ ${DateHelper.parseAndFormat(config.expiry_date)}`}
                    </Text>
                </div>
            ))}
        </Stack>
    );
};

type Props = {
    configs?: UserCustomConfig[];
};

export default UserCustomConfigsCell;
