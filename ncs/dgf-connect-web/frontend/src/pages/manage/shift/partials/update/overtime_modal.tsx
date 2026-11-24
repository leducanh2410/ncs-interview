import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import {
    Alert,
    Badge,
    Button,
    Checkbox,
    Flex,
    Group,
    Modal,
    NumberInput,
    ScrollArea,
    Stack,
    Table,
    Text,
} from '@mantine/core';
import { UserWithAbsenceCount } from '@/types/models/user';
import NotificationHelper from '@/helpers/notification.helper';
import {
    OvertimeEligibilityCandidate,
    useBulkUpdateOvertimeMutation,
    useOvertimeEligibilityMutation,
} from '@/pages/manage/shift/api';

type Props = {
    isOpen: boolean;
    onClose: () => void;
    shiftId: string;
    users: Record<string, UserWithAbsenceCount>;
    onSaved?: () => void;
};

// Render an hour amount compactly: whole numbers without decimals, otherwise one decimal.
const formatHours = (hours: number): string =>
    Number.isInteger(hours) ? String(hours) : (Math.round(hours * 10) / 10).toString();

const OvertimeModal = ({ isOpen, onClose, shiftId, users, onSaved }: Props) => {
    const { t } = useTranslation();
    const eligibilityMutation = useOvertimeEligibilityMutation(shiftId);
    const bulkMutation = useBulkUpdateOvertimeMutation(shiftId);

    const [overtimeMinutes, setOvertimeMinutes] = useState<number | string>('');
    const [empNeeded, setEmpNeeded] = useState<number | string>('');
    const [candidates, setCandidates] = useState<OvertimeEligibilityCandidate[] | null>(null);
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

    // Reset everything whenever the modal is opened.
    useEffect(() => {
        if (isOpen) {
            setOvertimeMinutes('');
            setEmpNeeded('');
            setCandidates(null);
            setSelectedIds(new Set());
        }
    }, [isOpen]);

    const cap = typeof empNeeded === 'number' ? empNeeded : null;
    const eligibleCount = useMemo(
        () => (candidates ? candidates.filter((c) => c.is_eligible).length : 0),
        [candidates],
    );

    const handleCalculate = async () => {
        if (typeof overtimeMinutes !== 'number' || overtimeMinutes < 1) {
            NotificationHelper.showError(t('Please enter the overtime minutes'));
            return;
        }
        try {
            const response = await eligibilityMutation.mutateAsync(overtimeMinutes);
            const result = response.data?.candidates ?? [];
            setCandidates(result);

            const eligible = result.filter((c) => c.is_eligible);
            // Skip the employee count -> select all eligible.
            // Eligible count equals the requested count -> auto select all.
            // Otherwise start from the currently-enabled eligible ones.
            const requested = typeof empNeeded === 'number' ? empNeeded : null;
            let initial: OvertimeEligibilityCandidate[];
            if (requested === null || eligible.length === requested) {
                initial = eligible;
            } else {
                initial = eligible.filter((c) => c.is_overtime_allowed);
                // Never start above the cap.
                if (initial.length > requested) {
                    initial = initial.slice(0, requested);
                }
            }
            setSelectedIds(new Set(initial.map((c) => c.shift_user_id)));
        } catch {
            NotificationHelper.showError(t('error|Could not calculate overtime eligibility'));
        }
    };

    const handleEmpNeededChange = (value: number | string) => {
        setEmpNeeded(value);
        // Trim the selection if the new cap is lower than what is already selected.
        // Trim by the order rows appear in the table (not the order the user ticked them),
        // so the rows that get unchecked are predictable: we keep the first ones shown.
        if (typeof value === 'number' && selectedIds.size > value) {
            setSelectedIds((prev) => {
                const orderedSelected = (candidates ?? [])
                    .filter((c) => prev.has(c.shift_user_id))
                    .map((c) => c.shift_user_id);
                return new Set(orderedSelected.slice(0, value));
            });
        }
    };

    const toggleSelected = (shiftUserId: number, checked: boolean) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (checked) {
                next.add(shiftUserId);
            } else {
                next.delete(shiftUserId);
            }
            return next;
        });
    };

    const canSelectMore = cap === null || selectedIds.size < cap;

    const handleConfirm = async () => {
        try {
            await bulkMutation.mutateAsync(Array.from(selectedIds));
            NotificationHelper.showSuccess(t('Successfully updated overtime status'));
            onSaved?.();
            onClose();
        } catch {
            NotificationHelper.showError(t('error|Could not update overtime status'));
        }
    };

    const rows = (candidates ?? []).map((candidate) => {
        const user = users[candidate.user_id.toString()];
        const checked = selectedIds.has(candidate.shift_user_id);
        const disabled = !candidate.is_eligible || (!checked && !canSelectMore);
        return (
            <Table.Tr key={candidate.shift_user_id} opacity={candidate.is_eligible ? 1 : 0.55}>
                <Table.Td>
                    <Checkbox
                        checked={checked}
                        disabled={disabled}
                        onChange={(event) => toggleSelected(candidate.shift_user_id, event.currentTarget.checked)}
                    />
                </Table.Td>
                <Table.Td>
                    <Text size="sm">{user?.name ?? `#${candidate.user_id}`}</Text>
                    {user?.staff_no && (
                        <Text size="xs" c="dimmed">
                            {user.staff_no}
                        </Text>
                    )}
                </Table.Td>
                <Table.Td>
                    <Text size="sm">
                        {formatHours(candidate.weekly_hours)} / {formatHours(candidate.max_hour_per_week)} {t('h')}
                    </Text>
                </Table.Td>
                <Table.Td>
                    <Text size="sm">
                        {formatHours(candidate.remaining_hours)} {t('h')}
                    </Text>
                </Table.Td>
                <Table.Td>
                    {candidate.is_eligible ? (
                        <Badge color="green" variant="light">
                            {t('Can overtime')}
                        </Badge>
                    ) : (
                        <Badge color="gray" variant="light">
                            {t('Exceeds weekly limit')}
                        </Badge>
                    )}
                </Table.Td>
            </Table.Tr>
        );
    });

    const overCap = cap !== null && selectedIds.size > cap;

    return (
        <Modal opened={isOpen} onClose={onClose} title={t('Manage overtime')} size="xl">
            <Stack gap="md">
                <Flex gap="md" align="flex-end" wrap="wrap">
                    <NumberInput
                        label={t('Overtime (minutes)')}
                        value={overtimeMinutes}
                        onChange={setOvertimeMinutes}
                        min={1}
                        step={15}
                        style={{ flex: 1, minWidth: 160 }}
                        required
                    />
                    <NumberInput
                        label={t('Number of employees needed')}
                        placeholder={t('Leave empty to enable for all eligible')}
                        value={empNeeded}
                        onChange={handleEmpNeededChange}
                        min={1}
                        style={{ flex: 1, minWidth: 160 }}
                    />
                    <Button onClick={handleCalculate} loading={eligibilityMutation.isPending}>
                        {t('Find eligible employees')}
                    </Button>
                </Flex>

                {candidates !== null && (
                    <>
                        <Group gap="md">
                            <Text size="sm">{t('Eligible: {{count}}', { count: eligibleCount })}</Text>
                            <Text size="sm">
                                {t('Selected: {{count}}', { count: selectedIds.size })}
                                {cap !== null ? ` / ${cap}` : ''}
                            </Text>
                        </Group>

                        <Text size="xs" c="dimmed">
                            {t(
                                'Saving sets the overtime state for the whole shift: selected employees get overtime enabled, and every other listed employee gets it disabled.',
                            )}
                        </Text>

                        {cap !== null && eligibleCount < cap && (
                            <Alert color="yellow" icon={<LuTriangleAlert size={16} />}>
                                {t('Only {{count}} eligible employees, fewer than requested', {
                                    count: eligibleCount,
                                })}
                            </Alert>
                        )}

                        {candidates.length === 0 ? (
                            <Text c="dimmed" ta="center" py="md">
                                {t('No employees in this shift')}
                            </Text>
                        ) : (
                            <ScrollArea.Autosize mah={360}>
                                <Table stickyHeader highlightOnHover>
                                    <Table.Thead>
                                        <Table.Tr>
                                            <Table.Th w={48} />
                                            <Table.Th>{t('Employee')}</Table.Th>
                                            <Table.Th>{t('Weekly hours')}</Table.Th>
                                            <Table.Th>{t('Remaining')}</Table.Th>
                                            <Table.Th>{t('Status')}</Table.Th>
                                        </Table.Tr>
                                    </Table.Thead>
                                    <Table.Tbody>{rows}</Table.Tbody>
                                </Table>
                            </ScrollArea.Autosize>
                        )}
                    </>
                )}

                <Flex justify="flex-end" gap="sm" mt="sm">
                    <Button variant="default" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        onClick={handleConfirm}
                        loading={bulkMutation.isPending}
                        disabled={candidates === null || overCap}
                    >
                        {t('Save overtime')}
                    </Button>
                </Flex>
            </Stack>
        </Modal>
    );
};

export default OvertimeModal;
