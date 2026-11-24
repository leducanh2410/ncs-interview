import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileSpreadsheet, LuSettings } from 'react-icons/lu';
import Link from 'next/link';
import { Anchor, Button, Card, CardSection, Flex, Group, Stack, Text } from '@mantine/core';
import { ShiftDetail } from '@/types/models/shift';
import { ShiftStatusEnum } from '@/enums/shift_status.enum';
import CommonHelper from '@/helpers/common.helper';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import RouteHelper from '@/helpers/route.helper';
import ShiftHelper from '@/helpers/shift.helper';
import { formatHours } from '@/pages/manage/shift/partials/weekly_work_load';

const ShiftInfoCard = ({ shift, hotBonusAmounts = [], canEdit, onUpdateConfig, onExport }: Props) => {
    const { t } = useTranslation();

    const { startTime, endTime } = DateHelper.formatWorkHour(
        shift.workplace_shift.start_time,
        shift.workplace_shift.end_time,
        shift.workplace_shift.start_break_time,
        shift.workplace_shift.end_break_time,
    );

    const status = ShiftHelper.getStatus(shift);

    return (
        <Card>
            <CardSection withBorder py="sm" px="md">
                <Flex justify="space-between" align="center">
                    <Text fw={700}>{t('Shift Info')}</Text>
                    <Flex gap="xs">
                        <Button
                            variant="light"
                            size="xs"
                            onClick={onExport}
                            leftSection={<LuFileSpreadsheet size={12} />}
                        >
                            {t('Export excel')}
                        </Button>
                        <Button
                            variant="light"
                            size="xs"
                            onClick={onUpdateConfig}
                            disabled={!canEdit}
                            leftSection={<LuSettings size={12} />}
                        >
                            {t('Update config')}
                        </Button>
                    </Flex>
                </Flex>
            </CardSection>
            <CardSection p="md">
                <Flex gap="md">
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Workplace')}
                            </Text>
                            <Text size="sm">
                                <Anchor
                                    component={Link}
                                    href={RouteHelper.getPath('ADMIN_WORKPLACE_DETAIL', {
                                        id: shift.workplace.id,
                                    })}
                                >
                                    {shift.workplace.name}
                                </Anchor>
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Time')}
                            </Text>
                            <Text size="sm">
                                {DateHelper.parseAndFormat(shift.date)} ({startTime} ~ {endTime})
                            </Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Work hours')}
                            </Text>
                            <Text size="sm" fw={700}>
                                {t('{{hours}} (excluding break)', { hours: formatHours(shift.work_hours, t) })}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Status')}
                            </Text>
                            <Text size="sm">{t(EnumHelper.getText(ShiftStatusEnum, status))}</Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Salary per hour')}
                            </Text>
                            <Text size="sm">{shift.salary_per_hour}</Text>
                        </Group>
                        {shift.is_overtime_by_duration && (
                            <Group px="sm" py="xs">
                                <Text size="sm" w={150}>
                                    {t('Overtime')}
                                </Text>
                                <Text size="sm">
                                    {t(
                                        "Counted beyond this shift duration; time outside the shift schedule still requires the employee's overtime permission",
                                    )}
                                </Text>
                            </Group>
                        )}
                    </Stack>
                    <Stack flex={1} gap={0}>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Created at')}
                            </Text>
                            <Text size="sm">
                                {DateHelper.parseAndFormat(shift.created_at, null, t('FORMAT_TIMESTAMP'))}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Closed By')}
                            </Text>
                            <Text size="sm">
                                {shift.creator
                                    ? `${shift.creator.name} (${DateHelper.parseAndFormat(shift.updated_at, null, t('FORMAT_TIMESTAMP'))})`
                                    : '-'}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs" bg="gray.2">
                            <Text size="sm" w={150}>
                                {t('Fixed employees')}
                            </Text>
                            <Text size="sm">
                                {shift.fixed_count} / {shift.fixed_total}
                                {shift.fixed_pending_count > 0 && ` (${shift.fixed_pending_count} ${t('pending')})`}
                            </Text>
                        </Group>
                        <Group px="sm" py="xs">
                            <Text size="sm" w={150}>
                                {t('Temp employees')}
                            </Text>
                            <Text size="sm">
                                {shift.temp_count} / {shift.temp_total}
                                {shift.temp_pending_count > 0 && ` (${shift.temp_pending_count} ${t('pending')})`}
                            </Text>
                        </Group>
                        {/* Shown for the shift rather than per employee: the system records that
                            someone answered a recruitment notification for this shift, not which
                            one, so an amount cannot be attributed to an individual. */}
                        {hotBonusAmounts.length > 0 && (
                            <Group px="sm" py="xs" bg="gray.2">
                                <Text size="sm" w={150}>
                                    {t('On-the-spot bonus')}
                                </Text>
                                <Text size="sm">
                                    {hotBonusAmounts.map((amount) => CommonHelper.formatNumber(amount)).join(', ')}
                                </Text>
                            </Group>
                        )}
                    </Stack>
                </Flex>
            </CardSection>
        </Card>
    );
};

type Props = {
    shift: ShiftDetail;
    /** Bonuses advertised by this shift's recruitment notifications; empty hides the row. */
    hotBonusAmounts?: number[];
    canEdit?: boolean;
    onUpdateConfig?: () => void;
    onExport?: () => void;
};

export default ShiftInfoCard;
