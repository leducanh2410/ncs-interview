import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, NumberInput, SimpleGrid, TextInput } from '@mantine/core';

export type CreateShiftValues = {
    fixedTotal: number;
    tempTotal: number;
    maxRegisteredTemp: number | null;
    breakMinutes: number | null;
};

const EMPTY_VALUES: CreateShiftValues = { fixedTotal: 0, tempTotal: 0, maxRegisteredTemp: null, breakMinutes: null };

type Props = {
    opened: boolean;
    onClose: () => void;
    onConfirm: (values: CreateShiftValues) => void;
    loading?: boolean;
    date: string;
    shiftTypeLabel: string;
    // Edit mode: prefill the fields and override the title. Omitted for the create flow.
    initialValues?: CreateShiftValues;
    title?: string;
};

// Reuses the create-shift field set (Decision 1/7) for both creating a concrete shift and editing
// an existing shift's config. The concrete shift persists even if the draft is later discarded.
// Date + shift type are pre-filled (and read-only) from the cell / header.
const CreateShiftModal: React.FC<Props> = ({
    opened,
    onClose,
    onConfirm,
    loading,
    date,
    shiftTypeLabel,
    initialValues,
    title,
}) => {
    const { t } = useTranslation();
    const [fixedTotal, setFixedTotal] = useState<number | string>(0);
    const [tempTotal, setTempTotal] = useState<number | string>(0);
    const [maxTemp, setMaxTemp] = useState<number | string>('');
    const [breakMinutes, setBreakMinutes] = useState<number | string>('');

    // Reset the fields from the (optional) initial values each time the modal opens, so reopening
    // for a different shift never shows stale numbers.
    useEffect(() => {
        if (!opened) return;
        const v = initialValues ?? EMPTY_VALUES;
        setFixedTotal(v.fixedTotal);
        setTempTotal(v.tempTotal);
        setMaxTemp(v.maxRegisteredTemp ?? '');
        setBreakMinutes(v.breakMinutes ?? '');
    }, [opened, initialValues]);

    const toNullableNumber = (v: number | string): number | null => (v === '' ? null : Number(v));

    const handleConfirm = () => {
        onConfirm({
            fixedTotal: Number(fixedTotal) || 0,
            tempTotal: Number(tempTotal) || 0,
            maxRegisteredTemp: toNullableNumber(maxTemp),
            breakMinutes: toNullableNumber(breakMinutes),
        });
    };

    return (
        <Modal opened={opened} onClose={onClose} title={title ?? t('Create shift')}>
            <SimpleGrid cols={1}>
                <TextInput label={t('Date')} value={date} disabled />
                <TextInput label={t('Shift')} value={shiftTypeLabel} disabled />
                <NumberInput
                    withAsterisk
                    label={t('Fixed position')}
                    value={fixedTotal}
                    onChange={setFixedTotal}
                    min={0}
                />
                <NumberInput
                    withAsterisk
                    label={t('Temporary position')}
                    value={tempTotal}
                    onChange={setTempTotal}
                    min={0}
                />
                <NumberInput label={t('Maximum registered temporary')} value={maxTemp} onChange={setMaxTemp} min={0} />
                <NumberInput label={t('Break time (minute)')} value={breakMinutes} onChange={setBreakMinutes} min={0} />
            </SimpleGrid>
            <Flex justify="flex-end" mt="md" gap="sm">
                <Button color="gray" onClick={onClose} disabled={loading}>
                    {t('Cancel')}
                </Button>
                <Button color="teal" leftSection={<LuSave size={16} />} onClick={handleConfirm} loading={loading}>
                    {t('Save')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default CreateShiftModal;
