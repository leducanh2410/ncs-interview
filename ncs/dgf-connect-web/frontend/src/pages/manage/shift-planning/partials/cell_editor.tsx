import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuEye, LuPencil, LuPlus, LuTriangleAlert } from 'react-icons/lu';
import { Alert, Badge, Box, Button, Divider, Group, SegmentedControl, Select, Stack, Text } from '@mantine/core';
import { Dayjs } from 'dayjs';
import Chip from '@/pages/manage/shift-planning/partials/chip';
import {
    PlanChip,
    PlanEmployee,
    employeeWarnings,
    fixedScheduleLines,
    getActivePositions,
    getShiftTypes,
    matchesFixedSchedule,
    worktimeGroupLabel,
} from '@/pages/manage/shift-planning/partials/data';

// One labeled field in the info panel: a fixed-width dimmed label + a value that
// fills the remaining width (and colours itself for warning states).
const InfoRow: React.FC<{ label: string; value: React.ReactNode; color?: string }> = ({ label, value, color }) => (
    <Group gap="xs" wrap="nowrap" align="flex-start">
        <Text size="xs" c="dimmed" w={88} style={{ flexShrink: 0 }}>
            {label}
        </Text>
        <Text size="xs" c={color} style={{ flex: 1, minWidth: 0 }}>
            {value}
        </Text>
    </Group>
);

type Props = {
    employee: PlanEmployee;
    date: Dayjs;
    readOnly: boolean; // lock held elsewhere (or not acquired) → view only
    chips: PlanChip[];
    dirtyIds: Set<string>; // chip ids with unsaved changes — marked with a corner dot
    errorReason?: string;
    // A concrete Shift already exists for this (date, shiftType)?
    shiftExists: (shiftTypeId: number) => boolean;
    onAdd: (shiftTypeId: number, positionId: number, isFixed: boolean) => void;
    onUpdate: (chipId: string, shiftTypeId: number, positionId: number, isFixed: boolean) => void;
    onRemove: (chipId: string) => void;
    onRequestCreateShift: (shiftTypeId: number, positionId: number, isFixed: boolean) => void;
};

// Inline cell editor: employee info header + a one-per-row list of existing
// assignments + an add/edit form. Only draft chips are editable; live chips show as
// read-only and can be toggled for planned removal (handled by onRemove in the parent).
const CellEditor: React.FC<Props> = ({
    employee,
    date,
    readOnly,
    chips,
    dirtyIds,
    errorReason,
    shiftExists,
    onAdd,
    onUpdate,
    onRemove,
    onRequestCreateShift,
}) => {
    const { t } = useTranslation();
    const shiftTypes = getShiftTypes();
    const positions = getActivePositions();

    // §5.1: Fixed/Temp defaults to Fixed when the employee has a matching FixedSchedule
    // for the physical weekday + selected shift-type, else Temp.
    const deriveFixed = (sTypeId: number | null): 'fixed' | 'temp' => {
        const weekday = date.day();
        const shift = sTypeId != null ? shiftTypes.find((s) => s.id === sTypeId) : null;
        if (shift) return matchesFixedSchedule(employee, weekday, shift.startTime) ? 'fixed' : 'temp';
        return employee.fixedSchedules.some((fs) => fs.weekday === weekday) ? 'fixed' : 'temp';
    };

    // A fresh add defaults to the first active position so the form is submit-ready.
    const defaultPositionId = positions.length > 0 ? String(positions[0].id) : null;

    // Select the first visible assignment by default so opening a populated cell
    // never looks like a fresh create. Draft and live chips both load into the edit
    // form; live edits are saved as update overlays until finalized.
    const firstChip = chips.length > 0 ? chips[0] : null;
    const [selectedChipId, setSelectedChipId] = useState<string | null>(firstChip?.id ?? null);
    const [shiftTypeId, setShiftTypeId] = useState<string | null>(firstChip ? String(firstChip.shiftTypeId) : null);
    const [positionId, setPositionId] = useState<string | null>(
        firstChip
            ? firstChip.positionId != null
                ? String(firstChip.positionId)
                : firstChip.source === 'draft'
                  ? defaultPositionId
                  : null
            : defaultPositionId,
    );
    const [fixed, setFixed] = useState<'fixed' | 'temp'>(
        firstChip ? (firstChip.isFixed ? 'fixed' : 'temp') : deriveFixed(null),
    );

    const selectChip = (chip: PlanChip) => {
        setSelectedChipId(chip.id);
        setShiftTypeId(String(chip.shiftTypeId));
        setPositionId(
            chip.positionId != null ? String(chip.positionId) : chip.source === 'draft' ? defaultPositionId : null,
        );
        setFixed(chip.isFixed ? 'fixed' : 'temp');
    };

    const startAdd = () => {
        setSelectedChipId(null);
        setShiftTypeId(null);
        setPositionId(defaultPositionId);
        setFixed(deriveFixed(null));
    };

    const handleShiftTypeChange = (v: string | null) => {
        setShiftTypeId(v);
        // Re-derive Fixed/Temp only while adding; when editing keep the stored value.
        if (selectedChipId == null) setFixed(deriveFixed(v ? Number(v) : null));
    };

    const handleRemove = (chip: PlanChip) => {
        onRemove(chip.id);
        if (chip.id !== selectedChipId) return;
        if (chip.source === 'live') return;
        const remaining = chips.filter((c) => c.id !== chip.id);
        if (remaining.length > 0) selectChip(remaining[0]);
        else startAdd();
    };

    const scheduleLines = fixedScheduleLines(employee);
    const warnings = employeeWarnings(employee, t);

    const selectedChip = selectedChipId ? (chips.find((chip) => chip.id === selectedChipId) ?? null) : null;
    const selectedShiftType = shiftTypeId ? Number(shiftTypeId) : null;
    const isEditing = selectedChip != null;
    const needsCreate = !isEditing && selectedShiftType != null && !shiftExists(selectedShiftType);
    const canSubmit = selectedShiftType != null && positionId != null;

    const handleSubmit = () => {
        if (!canSubmit || selectedShiftType == null || positionId == null) return;
        const isFixed = fixed === 'fixed';
        if (isEditing && selectedChipId != null) {
            onUpdate(selectedChipId, selectedShiftType, Number(positionId), isFixed);
        } else if (needsCreate) {
            onRequestCreateShift(selectedShiftType, Number(positionId), isFixed);
        } else {
            onAdd(selectedShiftType, Number(positionId), isFixed);
        }
    };

    const submitLabel = isEditing ? t('Update') : needsCreate ? t('Create shift & add') : t('Add');

    // Group shift types by whether a concrete Shift already exists for this date,
    // so the admin can tell at a glance which choices add to an existing shift vs.
    // open the create-shift form. Empty groups are omitted.
    const shiftTypeData = (() => {
        const created: { value: string; label: string }[] = [];
        const notCreated: { value: string; label: string }[] = [];
        shiftTypes.forEach((s) => {
            const option = { value: s.id.toString(), label: s.label };
            (shiftExists(s.id) ? created : notCreated).push(option);
        });
        const groups: { group: string; items: { value: string; label: string }[] }[] = [];
        if (created.length > 0) groups.push({ group: t('Created'), items: created });
        if (notCreated.length > 0) groups.push({ group: t('Not yet created'), items: notCreated });
        return groups;
    })();

    return (
        <Stack gap="xs" w={300}>
            <Group justify="space-between" wrap="nowrap" align="center" gap="xs">
                <Group gap={6} wrap="nowrap" style={{ minWidth: 0 }}>
                    {employee.hasFixedSchedule && (
                        <Box
                            w={7}
                            h={7}
                            bg="teal.5"
                            style={{ borderRadius: '50%', flexShrink: 0 }}
                            title={t('Fixed schedule')}
                        />
                    )}
                    <Text fw={600} size="sm" truncate>
                        {employee.name}
                    </Text>
                </Group>
                {readOnly && (
                    <Badge
                        color="gray"
                        variant="light"
                        radius="sm"
                        leftSection={<LuEye size={11} />}
                        style={{ flexShrink: 0 }}
                    >
                        {t('View only')}
                    </Badge>
                )}
            </Group>

            <Stack gap={4}>
                <InfoRow label={t('Staff No.')} value={employee.staffNo || '—'} />
                <InfoRow label={t('Worktime')} value={worktimeGroupLabel(employee.worktimeGroup, t)} />
                <InfoRow label={t('Date')} value={date.format('dddd, MMMM D, YYYY')} />
                {scheduleLines.length > 0 && <InfoRow label={t('Fixed schedule')} value={scheduleLines.join(' · ')} />}
            </Stack>

            {warnings.length > 0 && (
                <Stack gap={4}>
                    {warnings.map((w) => (
                        <Group key={w.message} gap={6} wrap="nowrap" align="center">
                            <Box c={w.color === 'red' ? 'red' : 'yellow.7'} style={{ display: 'flex', flexShrink: 0 }}>
                                <LuTriangleAlert size={14} />
                            </Box>
                            <Text size="xs" c={w.color === 'red' ? 'red' : 'yellow.8'}>
                                {w.message}
                            </Text>
                        </Group>
                    ))}
                </Stack>
            )}

            {errorReason && (
                <Alert color="red" p="xs" icon={<LuTriangleAlert size={16} />}>
                    {errorReason}
                </Alert>
            )}

            {chips.length > 0 && (
                <Stack gap={4}>
                    <Divider label={t('Assignments')} labelPosition="left" />
                    {chips.map((chip) => (
                        <Chip
                            key={chip.id}
                            chip={chip}
                            unsaved={dirtyIds.has(chip.id)}
                            selected={chip.id === selectedChipId}
                            onSelect={!readOnly ? () => selectChip(chip) : undefined}
                            onRemove={readOnly ? undefined : () => handleRemove(chip)}
                        />
                    ))}
                    {!readOnly && (
                        <Button
                            size="xs"
                            variant="light"
                            color="gray"
                            leftSection={<LuPlus size={14} />}
                            disabled={selectedChipId == null}
                            onClick={startAdd}
                        >
                            {t('Add shift')}
                        </Button>
                    )}
                </Stack>
            )}

            {!readOnly && (
                <>
                    <Divider label={isEditing ? t('Edit assignment') : t('Add assignment')} labelPosition="left" />

                    <Select
                        size="xs"
                        placeholder={t('Shift type')}
                        value={shiftTypeId}
                        onChange={handleShiftTypeChange}
                        comboboxProps={{ withinPortal: false }}
                        data={shiftTypeData}
                    />
                    <Select
                        size="xs"
                        placeholder={t('Position')}
                        value={positionId}
                        onChange={setPositionId}
                        comboboxProps={{ withinPortal: false }}
                        data={positions.map((p) => ({ value: p.id.toString(), label: p.name }))}
                    />
                    <SegmentedControl
                        size="xs"
                        value={fixed}
                        onChange={(v) => setFixed(v as 'fixed' | 'temp')}
                        data={[
                            { value: 'fixed', label: t('Fixed') },
                            { value: 'temp', label: t('Temp') },
                        ]}
                    />

                    {needsCreate && (
                        <Text size="xs" c="orange.7">
                            {t('No shift exists for this date yet — Add will open the create-shift form.')}
                        </Text>
                    )}

                    <Button
                        size="xs"
                        leftSection={isEditing ? <LuPencil size={14} /> : <LuPlus size={14} />}
                        disabled={!canSubmit}
                        onClick={handleSubmit}
                        variant={needsCreate ? 'light' : 'filled'}
                    >
                        {submitLabel}
                    </Button>
                </>
            )}
        </Stack>
    );
};

export default CellEditor;
