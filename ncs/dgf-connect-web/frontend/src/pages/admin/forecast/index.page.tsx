import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuArrowRight, LuInfo, LuSearch, LuTrendingUp } from 'react-icons/lu';
import Link from 'next/link';
import {
    ActionIcon,
    Alert,
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Flex,
    Group,
    Loader,
    NumberInput,
    Paper,
    Progress,
    ScrollArea,
    Select,
    SimpleGrid,
    Stack,
    Table,
    Text,
    TextInput,
    Title,
    Tooltip,
} from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import RouteConfig from '@/configs/route.config';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import {
    ForecastCandidate,
    ForecastCheckpoint,
    ForecastDemandStats,
    ForecastResponse,
} from '@/pages/admin/forecast/types';
import { useForecastOptionsQuery, useGenerateForecastMutation } from '@/pages/admin/forecast/api';

type ForecastFormValues = {
    workplace_id: string | null;
    workplace_shift_id: string | null;
    date: Date | string | null;
    requested_count: number | string;
    radius_km: number | string;
};

const DEFAULT_NEARBY_RADIUS_KM = 10;

type MetricProps = {
    label: string;
    value: string;
    sub?: string;
    description?: string;
};

type InfoLabelProps = {
    label: string;
    description?: string;
    uppercase?: boolean;
};

const formatNumber = (value: number | null | undefined): string => {
    if (value === null || value === undefined) return '-';
    return Number.isInteger(value) ? value.toString() : value.toFixed(1);
};

const formatRate = (value: number | null | undefined): string => {
    if (value === null || value === undefined) return '-';
    return `${value.toFixed(1)}%`;
};

const formatDateValue = (value: Date | string | null): string | null => {
    if (!value) return null;
    return value instanceof Date ? DateHelper.format(value, DATE_STORE_FORMAT) : value;
};

const riskColor = (risk: ForecastResponse['recommendation']['risk_level']): string => {
    if (risk === 'low') return 'green';
    if (risk === 'medium') return 'yellow';
    return 'red';
};

const scoreColor = (score: number): string => {
    if (score >= 60) return 'green';
    if (score >= 30) return 'yellow';
    return 'red';
};

const InfoLabel: React.FC<InfoLabelProps> = ({ label, description, uppercase }) => (
    <Group gap={4} align="center" wrap="nowrap">
        <Text
            size={uppercase ? 'xs' : 'sm'}
            c={uppercase ? 'dimmed' : undefined}
            tt={uppercase ? 'uppercase' : undefined}
            fw={uppercase ? 700 : 600}
        >
            {label}
        </Text>
        {description && (
            <Tooltip label={description} multiline maw={280} withArrow>
                <ActionIcon type="button" variant="subtle" color="gray" size="xs" aria-label={description}>
                    <LuInfo size={12} />
                </ActionIcon>
            </Tooltip>
        )}
    </Group>
);

const Metric: React.FC<MetricProps> = ({ label, value, sub, description }) => (
    <Paper withBorder p="md" radius="sm">
        <InfoLabel label={label} description={description} uppercase />
        <Text size="xl" fw={700} mt={4}>
            {value}
        </Text>
        {sub && (
            <Text size="xs" c="dimmed" mt={4}>
                {sub}
            </Text>
        )}
    </Paper>
);

const InfoBadge: React.FC<Required<Pick<MetricProps, 'label' | 'description'>>> = ({ label, description }) => (
    <Group gap={4} wrap="nowrap">
        <Badge variant="light" rightSection={<LuArrowRight size={12} />}>
            {label}
        </Badge>
        <Tooltip label={description} multiline maw={280} withArrow>
            <ActionIcon type="button" variant="subtle" color="gray" size="xs" aria-label={description}>
                <LuInfo size={12} />
            </ActionIcon>
        </Tooltip>
    </Group>
);

const ForecastPage = () => {
    const { t } = useTranslation();
    const optionsQuery = useForecastOptionsQuery();
    const forecastMutation = useGenerateForecastMutation();
    const [forecast, setForecast] = useState<ForecastResponse | null>(null);
    const [candidateFilter, setCandidateFilter] = useState('');

    const workplaces = optionsQuery.data?.data?.workplaces ?? [];
    const form = useForm<ForecastFormValues>({
        initialValues: {
            workplace_id: null,
            workplace_shift_id: null,
            date: DateHelper.now().add(7, 'day').format(DATE_STORE_FORMAT),
            requested_count: 1,
            radius_km: DEFAULT_NEARBY_RADIUS_KM,
        },
        validate: {
            workplace_id: (value) => (value ? null : t('Required')),
            workplace_shift_id: (value) => (value ? null : t('Required')),
            date: (value) => (value ? null : t('Required')),
            requested_count: (value) => (Number(value) >= 1 ? null : t('Must be at least 1')),
            radius_km: (value) => (Number(value) >= 0 ? null : t('Must be 0 or more')),
        },
    });

    const selectedWorkplace = useMemo(
        () => workplaces.find((workplace) => workplace.id.toString() === form.values.workplace_id) ?? null,
        [form.values.workplace_id, workplaces],
    );

    useEffect(() => {
        if (!form.values.workplace_id && workplaces.length > 0) {
            form.setFieldValue('workplace_id', workplaces[0].id.toString());
        }
    }, [form, workplaces]);

    useEffect(() => {
        if (selectedWorkplace && !form.values.workplace_shift_id && selectedWorkplace.shifts.length > 0) {
            form.setFieldValue('workplace_shift_id', selectedWorkplace.shifts[0].id.toString());
        }
    }, [form, selectedWorkplace]);

    const handleSubmit = async (values: ForecastFormValues) => {
        if (!values.workplace_id || !values.workplace_shift_id || !values.date) return;

        try {
            const response = await forecastMutation.mutateAsync({
                workplace_id: Number(values.workplace_id),
                workplace_shift_id: Number(values.workplace_shift_id),
                date: formatDateValue(values.date) as string,
                requested_count: Number(values.requested_count),
                radius_km: Number(values.radius_km),
            });
            setForecast(response.data);
        } catch {
            NotificationHelper.showError(t('Could not generate forecast'));
        }
    };

    const decisionLabel = (data: ForecastResponse): string => {
        switch (data.recommendation.decision) {
            case 'accept':
                return t('Accept requested count');
            case 'accept_with_backup':
                return t('Accept with backup plan');
            case 'negotiate':
                return t('Negotiate lower count');
            default:
                return t('Insufficient data');
        }
    };

    const riskLabel = (data: ForecastResponse): string => {
        switch (data.recommendation.risk_level) {
            case 'low':
                return t('Low risk');
            case 'medium':
                return t('Medium risk');
            default:
                return t('High risk');
        }
    };

    const confidenceLabel = (value: string): string => {
        if (value === 'high') return t('High confidence');
        if (value === 'medium') return t('Medium confidence');
        return t('Low confidence');
    };

    const sourceLabel = (source: string | null): string => {
        if (source === 'exact_shift') return t('Same workplace and time group');
        if (source === 'workplace') return t('Same workplace');
        if (source === 'accessible_workplaces') return t('Accessible workplaces');
        return t('No reliability source');
    };

    const reasonLabel = (code: string): string => {
        switch (code) {
            case 'low_sample':
                return t('Low sample');
            case 'reliability_fallback':
                return t('Fallback reliability');
            case 'active_pool_below_target':
                return t('Active pool below target');
            case 'high_no_show_rate':
                return t('High no-show rate');
            case 'frequent_h2_shortage':
                return t('Frequent shortage 2 hours before');
            case 'request_above_recent_average':
                return t('Above recent average');
            case 'reliability_unavailable':
                return t('Reliability unavailable');
            default:
                return t('Forecast note');
        }
    };

    const warningLabel = (code: string): string => {
        switch (code) {
            case 'low_similar_shift_sample':
                return t('Similar shift sample is low');
            case 'reliability_uses_fallback_scope':
                return t('Reliability uses fallback scope');
            case 'reliability_data_unavailable':
                return t('Reliability data is unavailable');
            default:
                return t('Forecast warning');
        }
    };

    const checkpointLabel = (checkpoint: ForecastCheckpoint): string => {
        if (checkpoint.key === 'd_14') return t('14 days before');
        if (checkpoint.key === 'd_7') return t('7 days before');
        if (checkpoint.key === 'd_3') return t('3 days before');
        if (checkpoint.key === 'd_1') return t('1 day before');
        if (checkpoint.key === 'h_6') return t('6 hours before');
        if (checkpoint.key === 'h_2') return t('2 hours before');
        if (checkpoint.key === 'start') return t('Start time');
        return checkpoint.label;
    };

    const renderDemandRows = (data: ForecastResponse) => {
        const rows: Array<[string, string, ForecastDemandStats]> = [
            [t('Recent 1 year'), t('All weekdays'), data.demand_context.recent_1_year.all_weekdays],
            [t('Recent 1 year'), t('Same weekday'), data.demand_context.recent_1_year.same_weekday],
            [t('Last 3 months'), t('All weekdays'), data.demand_context.last_3_months.all_weekdays],
            [t('Last 3 months'), t('Same weekday'), data.demand_context.last_3_months.same_weekday],
            [t('Same month last year'), t('All weekdays'), data.demand_context.same_month_last_year.all_weekdays],
            [t('Same month last year'), t('Same weekday'), data.demand_context.same_month_last_year.same_weekday],
        ];

        return rows.map(([period, scope, stats]) => (
            <Table.Tr key={`${period}-${scope}`}>
                <Table.Td>{period}</Table.Td>
                <Table.Td>{scope}</Table.Td>
                <Table.Td>{stats.shift_count}</Table.Td>
                <Table.Td>{formatNumber(stats.avg_requested)}</Table.Td>
                <Table.Td>{formatNumber(stats.avg_approved)}</Table.Td>
                <Table.Td>{formatNumber(stats.avg_check_in)}</Table.Td>
                <Table.Td>{formatRate(stats.avg_fill_rate)}</Table.Td>
                <Table.Td>{formatRate(stats.avg_show_up_rate)}</Table.Td>
            </Table.Tr>
        ));
    };

    const coveragePercent =
        forecast?.recommendation.expected_attendance !== null && forecast
            ? Math.min(100, (forecast.recommendation.expected_attendance / forecast.input.requested_count) * 100)
            : 0;

    const filteredCandidates = useMemo<ForecastCandidate[]>(() => {
        if (!forecast) return [];
        const query = candidateFilter.trim().toLowerCase();
        if (!query) return forecast.candidates;
        return forecast.candidates.filter(
            (candidate) =>
                candidate.name.toLowerCase().includes(query) ||
                (candidate.staff_no ?? '').toLowerCase().includes(query),
        );
    }, [forecast, candidateFilter]);

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Text>{t('Forecast')}</Text>
            </Breadcrumbs>

            <Flex justify="space-between" align="center" my="md" wrap="wrap" gap="md">
                <Title order={4}>{t('Forecast')}</Title>
            </Flex>

            <Paper p="md" withBorder>
                <form onSubmit={form.onSubmit(handleSubmit)}>
                    <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }} spacing="md">
                        <Select
                            withAsterisk
                            label={t('Workplace')}
                            searchable
                            data={WorkplaceHelper.toSelectOptions(workplaces, t)}
                            disabled={optionsQuery.isLoading}
                            {...form.getInputProps('workplace_id')}
                            onChange={(value) => {
                                form.setFieldValue('workplace_id', value);
                                form.setFieldValue('workplace_shift_id', null);
                                setForecast(null);
                            }}
                        />
                        <Select
                            withAsterisk
                            label={t('Shift')}
                            data={(selectedWorkplace?.shifts ?? []).map((shift) => ({
                                value: shift.id.toString(),
                                label: shift.label,
                            }))}
                            disabled={!selectedWorkplace || optionsQuery.isLoading}
                            {...form.getInputProps('workplace_shift_id')}
                            onChange={(value) => {
                                form.setFieldValue('workplace_shift_id', value);
                                setForecast(null);
                            }}
                        />
                        <DatePickerInput
                            withAsterisk
                            label={t('Date')}
                            valueFormat={t('FORMAT_DATE')}
                            minDate={DateHelper.now().toDate()}
                            {...form.getInputProps('date')}
                            onChange={(value) => {
                                form.setFieldValue('date', value);
                                setForecast(null);
                            }}
                        />
                        <NumberInput
                            withAsterisk
                            label={t('Requested employees')}
                            min={1}
                            {...form.getInputProps('requested_count')}
                            onChange={(value) => {
                                form.setFieldValue('requested_count', value);
                                setForecast(null);
                            }}
                        />
                        <NumberInput
                            label={t('Nearby radius (km)')}
                            description={t('Include the pool of workplaces within this distance')}
                            min={0}
                            {...form.getInputProps('radius_km')}
                            onChange={(value) => {
                                form.setFieldValue('radius_km', value);
                                setForecast(null);
                            }}
                        />
                    </SimpleGrid>
                    <Flex justify="flex-end" mt="md">
                        <Button
                            type="submit"
                            leftSection={<LuTrendingUp size={16} />}
                            loading={forecastMutation.isPending}
                            disabled={optionsQuery.isLoading || workplaces.length === 0}
                        >
                            {t('Run forecast')}
                        </Button>
                    </Flex>
                </form>
            </Paper>

            {optionsQuery.isLoading && (
                <Flex justify="center" py="xl">
                    <Loader />
                </Flex>
            )}

            {!optionsQuery.isLoading && workplaces.length === 0 && (
                <Alert mt="md" color="yellow" icon={<LuInfo size={16} />}>
                    {t('No accessible workplaces')}
                </Alert>
            )}

            {forecast && (
                <Stack mt="md" gap="md">
                    <Paper p="md" withBorder>
                        <Stack gap="sm">
                            <Group justify="space-between" align="flex-start">
                                <Stack gap={4}>
                                    <Text size="sm" c="dimmed">
                                        {t('Recommendation')}
                                    </Text>
                                    <Title order={3}>{decisionLabel(forecast)}</Title>
                                </Stack>
                                <Group gap="xs">
                                    <Badge color={riskColor(forecast.recommendation.risk_level)} variant="filled">
                                        {riskLabel(forecast)}
                                    </Badge>
                                    <Badge color="gray" variant="light">
                                        {confidenceLabel(forecast.recommendation.confidence)}
                                    </Badge>
                                </Group>
                            </Group>
                            <Stack gap={4}>
                                <Group justify="space-between">
                                    <Text size="xs" c="dimmed">
                                        {t('Expected coverage')}
                                    </Text>
                                    <Text size="xs" c="dimmed">
                                        {formatRate(coveragePercent)}
                                    </Text>
                                </Group>
                                <Progress
                                    value={coveragePercent}
                                    color={riskColor(forecast.recommendation.risk_level)}
                                />
                            </Stack>
                            {forecast.recommendation.reason_codes.length > 0 && (
                                <Group gap="xs">
                                    {forecast.recommendation.reason_codes.map((code) => (
                                        <Badge key={code} variant="light" color="gray">
                                            {reasonLabel(code)}
                                        </Badge>
                                    ))}
                                </Group>
                            )}
                            {forecast.warnings.length > 0 && (
                                <Alert color="yellow" icon={<LuInfo size={16} />}>
                                    <Group gap="xs">
                                        {forecast.warnings.map((code) => (
                                            <Badge key={code} color="yellow" variant="light">
                                                {warningLabel(code)}
                                            </Badge>
                                        ))}
                                    </Group>
                                </Alert>
                            )}
                        </Stack>
                    </Paper>

                    <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }} spacing="md">
                        <Metric
                            label={t('Safe commitment')}
                            value={formatNumber(forecast.recommendation.safe_commitment)}
                            sub={t('Requested {{count}}', { count: forecast.input.requested_count })}
                            description={t(
                                'Conservative headcount we can commit after pool size, show-up rate, and confidence adjustment.',
                            )}
                        />
                        <Metric
                            label={t('Expected attendance')}
                            value={formatNumber(forecast.recommendation.expected_attendance)}
                            sub={t('Expected shortage {{value}}', {
                                value: formatNumber(forecast.recommendation.expected_shortage),
                            })}
                            description={t(
                                'Expected people who will attend after applying show-up rate to the fillable approval count.',
                            )}
                        />
                        <Metric
                            label={t('Approval target')}
                            value={formatNumber(forecast.recommendation.suggested_approval_target)}
                            sub={t('Overbooking allowed')}
                            description={t('Number of approvals needed to cover the request after expected no-shows.')}
                        />
                        <Metric
                            label={t('Available pool')}
                            value={formatNumber(forecast.pool.available_pool_estimate)}
                            sub={t('Lead time {{hours}} hours', { hours: forecast.input.lead_time_hours })}
                            description={t(
                                'Weighted pool from recent attendance and fixed schedules, including the attendance of members at {{workplaces}} nearby workplaces within {{radius}} km, minus users already assigned on the same adjusted date.',
                                {
                                    workplaces: forecast.pool.nearby_workplace_count,
                                    radius: forecast.pool.radius_km,
                                },
                            )}
                        />
                    </SimpleGrid>

                    <SimpleGrid cols={{ base: 1, lg: 3 }} spacing="md">
                        <Metric
                            label={t('Show-up rate')}
                            value={formatRate(forecast.reliability.show_up_rate)}
                            sub={sourceLabel(forecast.reliability.source)}
                            description={t(
                                'Approved assignments with a check-in divided by approved assignments in the reliability sample.',
                            )}
                        />
                        <Metric
                            label={t('No-show rate')}
                            value={formatRate(forecast.reliability.no_show_rate)}
                            sub={t('Approved sample {{count}}', { count: forecast.reliability.approved_count })}
                            description={t(
                                'Remaining approved assignments without a check-in in the reliability sample.',
                            )}
                        />
                        <Metric
                            label={t('Shortage rate 6 hours before')}
                            value={formatRate(forecast.fill_speed.shortage_h6_rate)}
                            sub={t('Emergency add rate {{rate}}', {
                                rate: formatRate(forecast.fill_speed.emergency_add_rate),
                            })}
                            description={t(
                                'Share of similar historical shifts still below requested count 6 hours before start.',
                            )}
                        />
                    </SimpleGrid>

                    <Paper p="md" withBorder>
                        <Stack gap="sm">
                            <Group justify="space-between" align="center" wrap="wrap" gap="xs">
                                <Group gap="xs" align="center">
                                    <Title order={5}>{t('Candidate employees')}</Title>
                                    <Badge variant="light" color="gray">
                                        {forecast.candidates.length}
                                    </Badge>
                                    <Text size="xs" c="dimmed">
                                        {t('Ranked by attendance over the last 90 days')}
                                    </Text>
                                </Group>
                                <TextInput
                                    size="xs"
                                    w={220}
                                    placeholder={t('Search by name or staff no')}
                                    leftSection={<LuSearch size={14} />}
                                    value={candidateFilter}
                                    onChange={(event) => setCandidateFilter(event.currentTarget.value)}
                                />
                            </Group>
                            {forecast.candidates.length === 0 ? (
                                <Text size="sm" c="dimmed">
                                    {t('No callable candidates found')}
                                </Text>
                            ) : (
                                <ScrollArea.Autosize mah={380}>
                                    <Table striped highlightOnHover stickyHeader verticalSpacing={6} fz="sm">
                                        <Table.Thead>
                                            <Table.Tr>
                                                <Table.Th w={40}>#</Table.Th>
                                                <Table.Th>{t('Employee')}</Table.Th>
                                                <Table.Th w={170}>
                                                    <InfoLabel
                                                        label={t('Show-up confidence')}
                                                        description={t(
                                                            'How regularly the worker attended this shift over the last 90 days (a day worked only at a nearby workplace counts as 0.7), calibrated so a consistent regular reaches the ceiling. Capped at 90% because no one works every day the shift runs and acceptance is never certain.',
                                                        )}
                                                    />
                                                </Table.Th>
                                                <Table.Th w={120}>
                                                    <InfoLabel
                                                        label={t('Days worked')}
                                                        description={t(
                                                            'Distinct days the worker attended a matching shift, out of the days this workplace ran it in the last 90 days.',
                                                        )}
                                                    />
                                                </Table.Th>
                                                <Table.Th w={110}>{t('Last worked')}</Table.Th>
                                            </Table.Tr>
                                        </Table.Thead>
                                        <Table.Tbody>
                                            {filteredCandidates.map((candidate, index) => (
                                                <Table.Tr key={candidate.user_id}>
                                                    <Table.Td>
                                                        <Text size="xs" c="dimmed">
                                                            {index + 1}
                                                        </Text>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Group gap={6} wrap="nowrap">
                                                            <Text size="sm" fw={500}>
                                                                {candidate.name}
                                                            </Text>
                                                            {candidate.fixed_schedule && (
                                                                <Badge size="xs" variant="light" color="blue">
                                                                    {t('Fixed')}
                                                                </Badge>
                                                            )}
                                                        </Group>
                                                        {candidate.staff_no && (
                                                            <Text size="xs" c="dimmed">
                                                                {candidate.staff_no}
                                                            </Text>
                                                        )}
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Group gap={8} wrap="nowrap">
                                                            <Progress
                                                                value={candidate.score ?? 0}
                                                                color={scoreColor(candidate.score ?? 0)}
                                                                w={70}
                                                                size="sm"
                                                            />
                                                            <Text size="xs" fw={600} w={30} ta="right">
                                                                {formatRate(candidate.score)}
                                                            </Text>
                                                        </Group>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        <Group gap={6} wrap="nowrap">
                                                            <Text size="sm">
                                                                {candidate.worked_days}/{candidate.opportunity_days}
                                                            </Text>
                                                            {candidate.nearby_days > 0 && (
                                                                <Text size="xs" c="dimmed">
                                                                    {t('+{{count}} nearby', {
                                                                        count: candidate.nearby_days,
                                                                    })}
                                                                </Text>
                                                            )}
                                                        </Group>
                                                    </Table.Td>
                                                    <Table.Td>
                                                        {candidate.last_worked_date
                                                            ? DateHelper.parseAndFormat(candidate.last_worked_date)
                                                            : '-'}
                                                    </Table.Td>
                                                </Table.Tr>
                                            ))}
                                        </Table.Tbody>
                                    </Table>
                                </ScrollArea.Autosize>
                            )}
                        </Stack>
                    </Paper>

                    <Paper p="md" withBorder>
                        <Stack gap="sm">
                            <Title order={5}>{t('Demand context')}</Title>
                            <ScrollArea>
                                <Table striped highlightOnHover>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Period')}
                                                    description={t('Historical window used for the row.')}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Scope')}
                                                    description={t(
                                                        'Whether the row uses every weekday or only the target weekday.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Shifts')}
                                                    description={t('Number of historical shifts in this sample.')}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Avg request')}
                                                    description={t(
                                                        'Average requested headcount, fixed plus temporary.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Avg approved')}
                                                    description={t('Average approved assignments.')}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Avg check-in')}
                                                    description={t('Average check-ins from approved assignments.')}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Fill rate')}
                                                    description={t(
                                                        'Average approved assignments divided by requested headcount, capped at 100%.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Show-up rate')}
                                                    description={t(
                                                        'Average check-ins divided by approved assignments.',
                                                    )}
                                                />
                                            </Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>{renderDemandRows(forecast)}</Table.Tbody>
                                </Table>
                            </ScrollArea>
                        </Stack>
                    </Paper>

                    <Paper p="md" withBorder>
                        <Stack gap="sm">
                            <Title order={5}>{t('Fill speed')}</Title>
                            <ScrollArea>
                                <Table striped highlightOnHover>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Checkpoint')}
                                                    description={t(
                                                        'Time before shift start used to measure registration and approval progress.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Avg approved')}
                                                    description={t(
                                                        'Average approved assignments already approved by this checkpoint.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Registered rate')}
                                                    description={t(
                                                        'Average registered assignments by this checkpoint divided by requested headcount, capped at 100%.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Fill rate')}
                                                    description={t(
                                                        'Average approved assignments by this checkpoint divided by requested headcount, capped at 100%.',
                                                    )}
                                                />
                                            </Table.Th>
                                            <Table.Th>
                                                <InfoLabel
                                                    label={t('Shortage rate')}
                                                    description={t(
                                                        'Share of similar historical shifts still below requested count at this checkpoint.',
                                                    )}
                                                />
                                            </Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>
                                        {forecast.fill_speed.checkpoints.map((checkpoint) => (
                                            <Table.Tr key={checkpoint.key}>
                                                <Table.Td>{checkpointLabel(checkpoint)}</Table.Td>
                                                <Table.Td>{formatNumber(checkpoint.avg_approved_count)}</Table.Td>
                                                <Table.Td>{formatRate(checkpoint.average_registered_rate)}</Table.Td>
                                                <Table.Td>{formatRate(checkpoint.average_fill_rate)}</Table.Td>
                                                <Table.Td>{formatRate(checkpoint.shortage_rate)}</Table.Td>
                                            </Table.Tr>
                                        ))}
                                    </Table.Tbody>
                                </Table>
                            </ScrollArea>
                            <Group gap="xs">
                                <InfoBadge
                                    label={t('Reach target {{rate}}', {
                                        rate: formatRate(forecast.fill_speed.reach_target_rate),
                                    })}
                                    description={t(
                                        'Percent of similar historical shifts that eventually reached the requested headcount.',
                                    )}
                                />
                                <InfoBadge
                                    label={t('Average target time {{hours}} hours', {
                                        hours: formatNumber(forecast.fill_speed.average_hours_before_start_to_target),
                                    })}
                                    description={t(
                                        'Average hours before start when similar shifts reached requested headcount.',
                                    )}
                                />
                            </Group>
                        </Stack>
                    </Paper>

                    <Paper p="md" withBorder>
                        <Stack gap="sm">
                            <Title order={5}>{t('Pool detail')}</Title>
                            <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
                                <Metric
                                    label={t('Eligible members')}
                                    value={formatNumber(forecast.pool.eligible_workplace_members)}
                                    description={t(
                                        'Workplace members with staff number, not disabled, and not rejected.',
                                    )}
                                />
                                <Metric
                                    label={t('Active 90-day pool')}
                                    value={formatNumber(forecast.pool.active_90_day_pool)}
                                    description={t(
                                        'Weighted count of members who checked in for similar shifts in the last 90 days and are still free on the target date.',
                                    )}
                                />
                                <Metric
                                    label={t('Active 365-day pool')}
                                    value={formatNumber(forecast.pool.active_365_day_pool)}
                                    description={t(
                                        'Weighted count of members who checked in for similar shifts in the last 365 days and are still free on the target date.',
                                    )}
                                />
                                <Metric
                                    label={t('Fixed schedule matches')}
                                    value={formatNumber(forecast.pool.fixed_schedule_matches)}
                                    description={t(
                                        'Users with a fixed schedule matching the target weekday and time group.',
                                    )}
                                />
                                <Metric
                                    label={t('Already assigned same date')}
                                    value={formatNumber(forecast.pool.already_assigned_same_adjusted_date)}
                                    description={t('Approved users already assigned on the target adjusted date.')}
                                />
                                <Metric
                                    label={t('Extended pool')}
                                    value={formatNumber(forecast.pool.available_extended_pool)}
                                    description={t(
                                        'Broad available pool based on eligible members or active 365-day pool, minus same-date assignments.',
                                    )}
                                />
                                <Metric
                                    label={t('Absent notice rate')}
                                    value={formatRate(forecast.reliability.absent_notice_rate)}
                                    description={t(
                                        'Approved assignments with an absent notice divided by approved assignments.',
                                    )}
                                />
                                <Metric
                                    label={t('Avg emergency adds')}
                                    value={formatNumber(forecast.fill_speed.avg_emergency_add_count)}
                                    description={t('Average manager-added approvals within 6 hours before start.')}
                                />
                            </SimpleGrid>
                        </Stack>
                    </Paper>
                </Stack>
            )}
        </>
    );
};

export default ForecastPage;
