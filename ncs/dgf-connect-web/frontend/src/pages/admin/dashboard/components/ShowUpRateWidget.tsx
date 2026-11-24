import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuChevronRight } from 'react-icons/lu';
import { Badge, Box, Flex, Group, Progress, ScrollArea, Skeleton, Stack, Text, UnstyledButton } from '@mantine/core';
import ReliabilityRedactedHint from '@/pages/admin/dashboard/components/ReliabilityRedactedHint';
import { ReliabilityResponse, ReliabilityRow } from '@/pages/admin/dashboard/types';

interface Props {
    data: ReliabilityResponse | null;
    loading?: boolean;
    reliabilityAvailable?: boolean;
}

const DASH = '—';

const formatRate = (value: number | null): string => {
    if (value === null || value === undefined) return DASH;
    return `${value.toFixed(1)}%`;
};

const colorForRate = (value: number | null): string => {
    if (value === null) return 'gray';
    if (value < 70) return 'red';
    if (value < 85) return 'orange';
    return 'teal';
};

interface WorkplaceBarProps {
    row: ReliabilityRow;
    expanded: boolean;
    onToggle: () => void;
    slotRows: ReliabilityRow[];
}

const WorkplaceBar: React.FC<WorkplaceBarProps> = ({ row, expanded, onToggle, slotRows }) => {
    const { t } = useTranslation();
    const ratio = row.show_up_rate ?? 0;

    return (
        <Stack gap={4}>
            <UnstyledButton onClick={onToggle}>
                <Group justify="space-between" wrap="nowrap">
                    <Flex align="center" gap="xs">
                        {expanded ? <LuChevronDown size={14} /> : <LuChevronRight size={14} />}
                        <Text size="sm" fw={500}>
                            {row.workplace_name}
                        </Text>
                        {row.low_sample && (
                            <Badge size="xs" color="yellow" variant="light">
                                {t('Low sample')}
                            </Badge>
                        )}
                    </Flex>
                    <Group gap="md">
                        <Text size="xs" c="dimmed">
                            {t('{{checkins}}/{{assigned}} check-ins', {
                                checkins: row.check_in_count,
                                assigned: row.assigned_count,
                            })}
                        </Text>
                        <Text size="sm" fw={600} c={colorForRate(row.show_up_rate)}>
                            {formatRate(row.show_up_rate)}
                        </Text>
                    </Group>
                </Group>
            </UnstyledButton>
            <Progress value={ratio} color={colorForRate(row.show_up_rate)} radius="sm" size="md" />
            {expanded && slotRows.length > 0 && (
                <Box pl="lg" mt="xs">
                    <Stack gap={6}>
                        {slotRows.map((slot) => (
                            <Group
                                key={`${slot.workplace_id}-${slot.workplace_shift_id}`}
                                justify="space-between"
                                gap="md"
                            >
                                <Flex align="center" gap="xs">
                                    <Text size="xs">{slot.slot_label ?? `#${slot.workplace_shift_id}`}</Text>
                                    {slot.low_sample && (
                                        <Badge size="xs" color="yellow" variant="light">
                                            {t('Low sample')}
                                        </Badge>
                                    )}
                                </Flex>
                                <Group gap="md">
                                    <Text size="xs" c="dimmed">
                                        {t('{{checkins}}/{{assigned}}', {
                                            checkins: slot.check_in_count,
                                            assigned: slot.assigned_count,
                                        })}
                                    </Text>
                                    <Text size="xs" fw={600} c={colorForRate(slot.show_up_rate)}>
                                        {formatRate(slot.show_up_rate)}
                                    </Text>
                                </Group>
                            </Group>
                        ))}
                    </Stack>
                </Box>
            )}
        </Stack>
    );
};

const ShowUpRateWidget: React.FC<Props> = ({ data, loading = false, reliabilityAvailable = true }) => {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});

    const slotRowsByWorkplace = useMemo(() => {
        const map = new Map<number, ReliabilityRow[]>();
        (data?.by_workplace_slot ?? []).forEach((row) => {
            const list = map.get(row.workplace_id) ?? [];
            list.push(row);
            map.set(row.workplace_id, list);
        });
        return map;
    }, [data]);

    if (!reliabilityAvailable) {
        return <ReliabilityRedactedHint />;
    }

    if (loading || !data) {
        return (
            <Stack gap="sm">
                {Array.from({ length: 4 }).map((_, idx) => (
                    <Skeleton key={idx} h={36} />
                ))}
            </Stack>
        );
    }

    if (data.by_workplace.length === 0) {
        return (
            <Box py="xl" ta="center">
                <Text c="dimmed">{t('No reliability data for the selected workplaces.')}</Text>
            </Box>
        );
    }

    return (
        <ScrollArea.Autosize mah={420} type="auto" offsetScrollbars>
            <Stack gap="md" pr="xs">
                {data.by_workplace.map((row) => (
                    <WorkplaceBar
                        key={row.workplace_id}
                        row={row}
                        expanded={expanded[row.workplace_id] ?? false}
                        onToggle={() =>
                            setExpanded((prev) => ({
                                ...prev,
                                [row.workplace_id]: !prev[row.workplace_id],
                            }))
                        }
                        slotRows={slotRowsByWorkplace.get(row.workplace_id) ?? []}
                    />
                ))}
            </Stack>
        </ScrollArea.Autosize>
    );
};

export default ShowUpRateWidget;
