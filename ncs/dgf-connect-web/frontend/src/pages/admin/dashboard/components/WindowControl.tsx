import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar } from 'react-icons/lu';
import { Box, Flex, SegmentedControl, Text } from '@mantine/core';
import { DatePickerInput, DatesRangeValue } from '@mantine/dates';
import dayjs from 'dayjs';
import { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import { WindowDays, WindowSelection } from '@/pages/admin/dashboard/types';

interface Props {
    value: WindowSelection;
    onChange: (next: WindowSelection) => void;
    disabled?: boolean;
}

type SegValue = '30' | '60' | '90' | '180' | '365' | 'custom';

const PRESET_DAYS: WindowDays[] = [30, 60, 90, 180, 365];

const isPreset = (v: string): v is '30' | '60' | '90' | '180' | '365' => PRESET_DAYS.includes(Number(v) as WindowDays);

const toSegmentValue = (selection: WindowSelection): SegValue =>
    selection.kind === 'preset' ? (String(selection.days) as SegValue) : 'custom';

const toRangeValue = (selection: WindowSelection): DatesRangeValue =>
    selection.kind === 'custom' ? [selection.startDate, selection.endDate] : [null, null];

const WindowControl: React.FC<Props> = ({ value, onChange, disabled = false }) => {
    const { t } = useTranslation();

    const [draftRange, setDraftRange] = useState<DatesRangeValue>(() => toRangeValue(value));

    useEffect(() => {
        setDraftRange(toRangeValue(value));
    }, [value]);

    const handleSegmentChange = (next: string) => {
        if (isPreset(next)) {
            onChange({ kind: 'preset', days: Number(next) as WindowDays });
            return;
        }
        // Switching to "Custom" — seed with the last 30 days.
        const seedEnd = dayjs().subtract(1, 'day');
        const seedStart = seedEnd.subtract(29, 'day');
        onChange({
            kind: 'custom',
            startDate: seedStart.format(DATE_STORE_FORMAT),
            endDate: seedEnd.format(DATE_STORE_FORMAT),
        });
    };

    const handleRangeChange = (next: DatesRangeValue) => {
        setDraftRange(next);
        const [start, end] = next;
        if (!start || !end) return;
        onChange({
            kind: 'custom',
            startDate: dayjs(start).format(DATE_STORE_FORMAT),
            endDate: dayjs(end).format(DATE_STORE_FORMAT),
        });
    };

    const yesterday = dayjs().subtract(1, 'day').format(DATE_STORE_FORMAT);

    return (
        <Box>
            <Text size="sm" fw={500} mb={4} ta="right">
                {t('Historical window')}
            </Text>
            <Flex gap="sm" align="center" wrap="wrap">
                <SegmentedControl
                    value={toSegmentValue(value)}
                    onChange={handleSegmentChange}
                    disabled={disabled}
                    data={[
                        { value: '30', label: t('{{count}} days', { count: 30 }) },
                        { value: '60', label: t('{{count}} days', { count: 60 }) },
                        { value: '90', label: t('{{count}} days', { count: 90 }) },
                        { value: '180', label: t('{{count}} days', { count: 180 }) },
                        { value: '365', label: t('{{count}} days', { count: 365 }) },
                        { value: 'custom', label: t('Custom') },
                    ]}
                />
                {value.kind === 'custom' && (
                    <DatePickerInput
                        type="range"
                        value={draftRange}
                        onChange={handleRangeChange}
                        valueFormat={t('FORMAT_DATE')}
                        leftSection={<LuCalendar size={16} />}
                        numberOfColumns={2}
                        allowSingleDateInRange
                        maxDate={yesterday}
                        clearable={false}
                        disabled={disabled}
                        miw={260}
                        aria-label={t('Custom date range')}
                    />
                )}
            </Flex>
        </Box>
    );
};

export default WindowControl;
