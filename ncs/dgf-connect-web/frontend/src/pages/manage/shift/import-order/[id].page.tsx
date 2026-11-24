import React, { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Alert,
    Anchor,
    Badge,
    Box,
    Breadcrumbs,
    Button,
    Card,
    CardSection,
    Flex,
    Group,
    List,
    Loader,
    Paper,
    SegmentedControl,
    Stack,
    Table,
    Text,
    Title,
} from '@mantine/core';
import { MonthPickerInput } from '@mantine/dates';
import dayjs from 'dayjs';
import { TFunction } from 'i18next';
import {
    ShiftOrderConfirmDecision,
    ShiftOrderImportError,
    ShiftOrderPositionTotal,
    ShiftOrderPreviewDay,
    ShiftOrderPreviewShift,
} from '@/types/models/import_batch';
import RouteConfig from '@/configs/route.config';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import {
    getShiftOrderImportErrorMessage,
    toShiftOrderImportErrors,
} from '@/pages/manage/shift/import-order/error_message';
import { useAuthLoaded, usePermissions } from '@/hooks/usePermission';
import {
    useConfirmShiftOrderImportMutation,
    useGetShiftOrderPreviewQuery,
    useUpdateShiftOrderMonthMutation,
} from '@/pages/manage/shift/import-order/api';

const MONTH_STORE_FORMAT = 'YYYY-MM';

const SOURCE_FILE = 'file';
const SOURCE_SYSTEM = 'system';

type DecisionMap = Record<string, string>;

const dayKey = (day: ShiftOrderPreviewDay) => `${day.workplace_shift_id}|${day.date}`;

const positionTotalsMap = (positions: ShiftOrderPositionTotal[]): Record<number, number> => {
    const map: Record<number, number> = {};
    for (const position of positions) {
        map[position.workplace_position_id] = position.fixed_total;
    }
    return map;
};

const assignedTotalsMap = (positions: ShiftOrderPositionTotal[]): Record<number, number> => {
    const map: Record<number, number> = {};
    for (const position of positions) {
        map[position.workplace_position_id] = position.assigned_count ?? 0;
    }
    return map;
};

const sumFixedTotals = (positions: ShiftOrderPositionTotal[]) =>
    positions.reduce((total, position) => total + position.fixed_total, 0);

type ComparisonLine = {
    key: string;
    label: string;
    emphasis: boolean;
    file: number | null;
    system: number | null;
    systemAssigned: number | null;
    changed: boolean;
};

const buildComparisonLines = (day: ShiftOrderPreviewDay, t: TFunction): ComparisonLine[] => {
    const existing = day.existing;
    const compare = day.status === 'diff' && existing !== null;
    const fileTotals = positionTotalsMap(day.positions);
    const systemTotals = existing ? positionTotalsMap(existing.positions) : {};
    const systemAssigned = existing ? assignedTotalsMap(existing.positions) : {};

    const names: Record<number, string> = {};
    for (const position of [...day.positions, ...(existing?.positions ?? [])]) {
        if (!names[position.workplace_position_id] && position.name) {
            names[position.workplace_position_id] = position.name;
        }
    }

    const positionIds: number[] = [];
    for (const position of [...day.positions, ...(existing?.positions ?? [])]) {
        if (!positionIds.includes(position.workplace_position_id)) {
            positionIds.push(position.workplace_position_id);
        }
    }

    const lines: ComparisonLine[] = [
        {
            key: 'fixed-total',
            label: t('Fixed total'),
            emphasis: true,
            file: day.fixed_total,
            system: existing ? existing.fixed_total : null,
            systemAssigned: existing ? existing.assigned_count : null,
            changed: compare && existing?.fixed_total !== day.fixed_total,
        },
    ];

    for (const positionId of positionIds) {
        const file = fileTotals[positionId] ?? 0;
        const system = existing ? (systemTotals[positionId] ?? 0) : null;
        lines.push({
            key: `position-${positionId}`,
            label: names[positionId],
            emphasis: false,
            file,
            system,
            systemAssigned: existing ? (systemAssigned[positionId] ?? 0) : null,
            changed: compare && system !== file,
        });
    }

    const systemSpot = existing ? existing.fixed_total - sumFixedTotals(existing.positions) : null;
    if (day.spot_total > 0 || (systemSpot ?? 0) > 0) {
        lines.push({
            key: 'spot',
            label: t('spot(Unassigned)'),
            emphasis: false,
            file: day.spot_total,
            system: systemSpot,
            systemAssigned: null,
            changed: compare && systemSpot !== day.spot_total,
        });
    }

    if (existing && existing.unassigned_position_count > 0) {
        lines.push({
            key: 'without-position',
            label: t('Without a position'),
            emphasis: false,
            file: null,
            system: null,
            systemAssigned: existing.unassigned_position_count,
            changed: false,
        });
    }

    return lines;
};

const decisionHint = (day: ShiftOrderPreviewDay, decision: string | undefined, t: TFunction) => {
    if (day.status === 'new') {
        return { label: t('Will be created'), color: 'dimmed' };
    }

    if (day.status === 'match') {
        return { label: t('No change'), color: 'dimmed' };
    }

    if (decision === SOURCE_FILE) {
        return { label: t('Using the file'), color: 'dimmed' };
    }

    if (decision === SOURCE_SYSTEM) {
        return { label: t('Keeping the system'), color: 'dimmed' };
    }

    return { label: t('Not chosen yet'), color: 'orange.8' };
};

type ComparisonColumnProps = {
    lines: ComparisonLine[];
    side: 'file' | 'system';
    hasShift: boolean;
};

const ComparisonColumn = ({ lines, side, hasShift }: ComparisonColumnProps) => {
    const { t } = useTranslation();

    if (!hasShift) {
        return (
            <Text size="xs" c="dimmed">
                {t('Not in the system yet')}
            </Text>
        );
    }

    const hasBreakdown = lines.length > 1;

    return (
        <Stack gap={2}>
            {lines.map((line) => {
                const value = side === 'file' ? line.file : line.system;
                const assigned = side === 'system' && line.systemAssigned ? line.systemAssigned : null;
                const color = line.changed ? 'orange.8' : undefined;

                return (
                    <Group key={line.key} gap="xs" justify="space-between" wrap="nowrap">
                        <Text size="xs" c={color} fw={line.emphasis ? 600 : undefined}>
                            {line.label}
                        </Text>
                        <Text
                            size="xs"
                            c={color}
                            fw={line.changed || line.emphasis ? 700 : 500}
                            style={{ whiteSpace: 'nowrap' }}
                        >
                            {value ?? '-'}
                            {assigned !== null && (
                                <Text size="xs" c="dimmed" fw={400} span>
                                    {' '}
                                    ({t('Assigned')}: {assigned})
                                </Text>
                            )}
                        </Text>
                    </Group>
                );
            })}
            {!hasBreakdown && (
                <Text size="xs" c="dimmed">
                    {t('No position breakdown')}
                </Text>
            )}
        </Stack>
    );
};

const ShiftOrderImportPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = (router.query.id as string) || '';
    const perms = usePermissions(['SHIFT_IMPORT_ORDER']);
    const authLoaded = useAuthLoaded();

    const { data, isLoading, error, refetch } = useGetShiftOrderPreviewQuery(
        id,
        authLoaded && perms.SHIFT_IMPORT_ORDER,
    );
    const updateMonthMutation = useUpdateShiftOrderMonthMutation(id);
    const confirmMutation = useConfirmShiftOrderImportMutation(id);

    const [decisions, setDecisions] = useState<DecisionMap>({});
    const [errors, setErrors] = useState<ShiftOrderImportError[]>([]);

    const preview = data?.data?.preview;
    const month = data?.data?.month ?? null;

    const diffKeys = useMemo(() => {
        const keys: string[] = [];
        for (const workplace of preview?.workplaces ?? []) {
            for (const shift of workplace.shifts) {
                for (const day of shift.days) {
                    if (day.status === 'diff') {
                        keys.push(dayKey(day));
                    }
                }
            }
        }
        return keys;
    }, [preview]);

    const undecidedCount = diffKeys.filter((key) => !decisions[key]).length;

    const setAllDecisions = (source: string) => {
        setDecisions(Object.fromEntries(diffKeys.map((key) => [key, source])));
    };

    const handleMonthChange = async (value: string | null) => {
        if (!value) {
            return;
        }

        setErrors([]);
        try {
            await updateMonthMutation.mutateAsync(dayjs(value).format(MONTH_STORE_FORMAT));
            setDecisions({});
            await refetch();
        } catch (error) {
            setErrors(toShiftOrderImportErrors(error));
        }
    };

    const handleConfirm = async () => {
        setErrors([]);
        const payload: ShiftOrderConfirmDecision[] = diffKeys.map((key) => {
            const [workplaceShiftId, date] = key.split('|');
            return {
                workplace_shift_id: Number(workplaceShiftId),
                date,
                source: (decisions[key] ?? SOURCE_FILE) as ShiftOrderConfirmDecision['source'],
            };
        });

        try {
            const response = await confirmMutation.mutateAsync(payload);
            NotificationHelper.showSuccess(
                t('Created {{created}} shifts, updated {{updated}} shifts', {
                    created: response.data?.created_count ?? 0,
                    updated: response.data?.updated_count ?? 0,
                }),
            );
            await router.push(RouteHelper.getPath('IMPORT_BATCH_DETAIL', { id }));
        } catch (error) {
            const confirmErrors = toShiftOrderImportErrors(error);
            setErrors(confirmErrors);

            if (confirmErrors.some((item) => item.code === 'decision_required')) {
                await refetch();
            }
        }
    };

    const breadcrumbs = (
        <Breadcrumbs>
            <Anchor component={Link} href={RouteConfig.HOME.path}>
                {t('Home')}
            </Anchor>
            <Anchor component={Link} href={RouteConfig.MANAGE_SHIFT_LIST.path}>
                {t('Shift List')}
            </Anchor>
            <Text>{t('Import shift order')}</Text>
        </Breadcrumbs>
    );

    if (authLoaded && !perms.SHIFT_IMPORT_ORDER) {
        return (
            <>
                {breadcrumbs}
                <Alert color="red" mt="md">
                    {t('You do not have permission to access this page')}
                </Alert>
            </>
        );
    }

    if (!authLoaded || isLoading) {
        return (
            <>
                {breadcrumbs}
                <Flex w="100%" h="300px" justify="center" align="center">
                    <Loader />
                </Flex>
            </>
        );
    }

    if (!preview) {
        const isAlreadyConfirmed = error instanceof ApiError && error.message === 'SHIFT_ORDER_BATCH_NOT_PENDING';

        return (
            <>
                {breadcrumbs}
                <Alert color={isAlreadyConfirmed ? 'blue' : 'red'} mt="md">
                    {isAlreadyConfirmed ? (
                        <Group gap="xs">
                            <Text size="sm">{t('This import has already been confirmed')}</Text>
                            <Anchor
                                component={Link}
                                href={RouteHelper.getPath('IMPORT_BATCH_DETAIL', { id })}
                                size="sm"
                            >
                                {t('View')}
                            </Anchor>
                        </Group>
                    ) : (
                        t('Import batch not found')
                    )}
                </Alert>
            </>
        );
    }

    return (
        <>
            {breadcrumbs}
            <Title order={4} lh="48px" my="md">
                {t('Import shift order')}
            </Title>

            <Paper p="md" mb="md">
                <Flex gap="lg" align="flex-end" wrap="wrap">
                    <Box>
                        <Text size="sm" c="dimmed">
                            {t('File')}
                        </Text>
                        <Text size="sm">{data?.data?.batch.file_name}</Text>
                    </Box>
                    <MonthPickerInput
                        label={t('Month')}
                        valueFormat={t('FORMAT_MONTH')}
                        value={month ? `${month}-01` : null}
                        onChange={handleMonthChange}
                        disabled={updateMonthMutation.isPending}
                        w={200}
                    />
                    <Group gap="xs">
                        <Badge color="blue" variant="light">
                            {t('New')}: {preview.summary.new_count}
                        </Badge>
                        <Badge color="green" variant="light">
                            {t('Matched')}: {preview.summary.match_count}
                        </Badge>
                        <Badge color="orange" variant="light">
                            {t('Different')}: {preview.summary.diff_count}
                        </Badge>
                    </Group>
                </Flex>
            </Paper>

            {errors.length > 0 && (
                <Alert color="red" title={t('The file cannot be imported')} mb="md">
                    <List size="sm" spacing={4}>
                        {errors.map((error, index) => (
                            <List.Item key={`${error.code}-${error.cell ?? error.date ?? index}`}>
                                {getShiftOrderImportErrorMessage(error, t)}
                            </List.Item>
                        ))}
                    </List>
                </Alert>
            )}

            {preview.summary.diff_count > 0 && (
                <Flex gap="sm" mb="md" align="center" wrap="wrap">
                    {undecidedCount > 0 && (
                        <Text size="sm">{t('{{count}} shifts still need a decision', { count: undecidedCount })}</Text>
                    )}
                    <Button size="xs" variant="light" onClick={() => setAllDecisions(SOURCE_FILE)}>
                        {t('Use the file for all')}
                    </Button>
                    <Button size="xs" variant="light" onClick={() => setAllDecisions(SOURCE_SYSTEM)}>
                        {t('Keep the system for all')}
                    </Button>
                </Flex>
            )}

            <Stack gap="md">
                {preview.workplaces.map((workplace) => (
                    <Card key={workplace.workplace_id} withBorder>
                        <CardSection withBorder py="sm" px="md">
                            <Text fw={700}>{workplace.workplace_name}</Text>
                        </CardSection>
                        <CardSection p="md">
                            <Stack gap="lg">
                                {workplace.shifts.map((shift: ShiftOrderPreviewShift) => (
                                    <Box key={shift.workplace_shift_id}>
                                        <Text fw={600} size="sm" mb="xs">
                                            {shift.shift_label}
                                        </Text>
                                        <Table withTableBorder withColumnBorders striped>
                                            <Table.Thead>
                                                <Table.Tr>
                                                    <Table.Th w={120}>{t('Date')}</Table.Th>
                                                    <Table.Th w={110}>{t('Status')}</Table.Th>
                                                    <Table.Th w={250} bg="blue.0">
                                                        {t('Shift order in the file')}
                                                    </Table.Th>
                                                    <Table.Th w={280} bg="gray.1">
                                                        {t('Shift currently in the system')}
                                                    </Table.Th>
                                                    <Table.Th w={210}>{t('Apply')}</Table.Th>
                                                </Table.Tr>
                                            </Table.Thead>
                                            <Table.Tbody>
                                                {shift.days.map((day) => {
                                                    const key = dayKey(day);
                                                    const lines = buildComparisonLines(day, t);
                                                    const hint = decisionHint(day, decisions[key], t);
                                                    return (
                                                        <Table.Tr key={key}>
                                                            <Table.Td>{DateHelper.parseAndFormat(day.date)}</Table.Td>
                                                            <Table.Td>
                                                                {day.status === 'new' && (
                                                                    <Badge color="blue" variant="light">
                                                                        {t('New')}
                                                                    </Badge>
                                                                )}
                                                                {day.status === 'match' && (
                                                                    <Badge color="green" variant="light">
                                                                        {t('Matched')}
                                                                    </Badge>
                                                                )}
                                                                {day.status === 'diff' && (
                                                                    <Badge color="orange" variant="light">
                                                                        {t('Different')}
                                                                    </Badge>
                                                                )}
                                                            </Table.Td>
                                                            <Table.Td>
                                                                <ComparisonColumn lines={lines} side="file" hasShift />
                                                            </Table.Td>
                                                            <Table.Td>
                                                                <ComparisonColumn
                                                                    lines={lines}
                                                                    side="system"
                                                                    hasShift={day.existing !== null}
                                                                />
                                                            </Table.Td>
                                                            <Table.Td>
                                                                <Stack gap={4}>
                                                                    {day.status === 'diff' && (
                                                                        <SegmentedControl
                                                                            size="xs"
                                                                            fullWidth
                                                                            color="blue"
                                                                            withItemsBorders={false}
                                                                            styles={{ root: { padding: 0 } }}
                                                                            value={decisions[key] ?? ''}
                                                                            onChange={(value) =>
                                                                                setDecisions((current) => ({
                                                                                    ...current,
                                                                                    [key]: value,
                                                                                }))
                                                                            }
                                                                            data={[
                                                                                {
                                                                                    value: SOURCE_FILE,
                                                                                    label: t('File'),
                                                                                },
                                                                                {
                                                                                    value: SOURCE_SYSTEM,
                                                                                    label: t('System'),
                                                                                },
                                                                            ]}
                                                                        />
                                                                    )}
                                                                    <Text size="xs" ta="center" c={hint.color}>
                                                                        {hint.label}
                                                                    </Text>
                                                                </Stack>
                                                            </Table.Td>
                                                        </Table.Tr>
                                                    );
                                                })}
                                            </Table.Tbody>
                                        </Table>
                                    </Box>
                                ))}
                            </Stack>
                        </CardSection>
                    </Card>
                ))}
            </Stack>

            <Flex justify="flex-end" mt="md">
                <Button
                    leftSection={<LuCheck size={16} />}
                    onClick={handleConfirm}
                    loading={confirmMutation.isPending}
                    disabled={undecidedCount > 0}
                >
                    {t('Create shifts')}
                </Button>
            </Flex>
        </>
    );
};

export default ShiftOrderImportPage;
