import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, Button, Flex, List, Modal, Stack, Text } from '@mantine/core';

export type ValidationErrorEntry = {
    employeeName: string;
    dateLabel: string;
    reason: string;
};

// Group entries under their date, keeping dates in first-appearance order so the
// list mirrors the order the server reported the errors in.
const groupByDate = (entries: ValidationErrorEntry[]): { dateLabel: string; items: ValidationErrorEntry[] }[] => {
    const groups: { dateLabel: string; items: ValidationErrorEntry[] }[] = [];
    const byDate = new Map<string, ValidationErrorEntry[]>();
    entries.forEach((entry) => {
        let items = byDate.get(entry.dateLabel);
        if (!items) {
            items = [];
            byDate.set(entry.dateLabel, items);
            groups.push({ dateLabel: entry.dateLabel, items });
        }
        items.push(entry);
    });
    return groups;
};

type Props = {
    opened: boolean;
    onClose: () => void;
    entries: ValidationErrorEntry[];
    // Which action triggered the validation (used in the body copy).
    trigger: 'save' | 'finalize';
};

// Validation gate for Save / Finalize: lists per-cell validation errors before
// anything is committed. After the modal closes, the affected cells stay red so
// the admin can find and fix them, then re-attempt (spec §3.3).
const ValidationErrorsModal: React.FC<Props> = ({ opened, onClose, entries, trigger }) => {
    const { t } = useTranslation();
    const title = trigger === 'save' ? t('Cannot save draft') : t('Cannot finalize');

    return (
        <Modal opened={opened} onClose={onClose} title={title} size="lg">
            <Alert color="red" variant="light" mb="sm" icon={<LuTriangleAlert size={16} />}>
                {trigger === 'save'
                    ? t('Fix the following cells before saving the draft. They stay marked until resolved.')
                    : t('Some selected dates have blocking errors. Fix the cells below and resubmit.')}
            </Alert>
            {entries.length === 0 ? (
                <Text size="sm">{t('No validation errors.')}</Text>
            ) : (
                <Stack gap="md">
                    {groupByDate(entries).map(({ dateLabel, items }) => (
                        <Stack key={dateLabel} gap={4}>
                            <Text size="sm" fw={600}>
                                {dateLabel}
                            </Text>
                            <List size="sm" spacing={4} withPadding>
                                {items.map((e, i) => (
                                    <List.Item key={i}>
                                        <Text span fw={500}>
                                            {e.employeeName}
                                        </Text>{' '}
                                        — {e.reason}
                                    </List.Item>
                                ))}
                            </List>
                        </Stack>
                    ))}
                </Stack>
            )}
            <Flex justify="flex-end" mt="md">
                <Button onClick={onClose}>{t('Close')}</Button>
            </Flex>
        </Modal>
    );
};

export default ValidationErrorsModal;
