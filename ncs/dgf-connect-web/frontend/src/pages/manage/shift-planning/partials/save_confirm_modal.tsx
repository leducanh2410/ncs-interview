import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuMinus, LuPencil, LuPlus, LuSave, LuTriangleAlert } from 'react-icons/lu';
import { Alert, Box, Button, Divider, Flex, Group, Modal, ScrollArea, Stack, Text } from '@mantine/core';

export type DraftDiffKind = 'add' | 'remove' | 'edit';

export type DraftDiffEntry = {
    kind: DraftDiffKind;
    liveImpact: boolean; // takes effect on live data immediately at Save, may notify
    employeeName: string;
    dateLabel: string;
    chipLabel: string;
};

const KIND_COLOR: Record<DraftDiffKind, string> = {
    add: 'teal',
    edit: 'blue',
    remove: 'red',
};

const KIND_ICON: Record<DraftDiffKind, React.ReactNode> = {
    add: <LuPlus size={12} />,
    edit: <LuPencil size={12} />,
    remove: <LuMinus size={12} />,
};

type Props = {
    opened: boolean;
    onClose: () => void;
    onConfirm: () => void;
    entries: DraftDiffEntry[];
};

// Save changes confirmation rendered as one flat list. The modal is the only elevated surface
// (no nested cards / inner shadow). The immediate, hard-to-undo effect of live changes is the
// only thing shown in a warning state — a single Alert up top — so the entry list itself stays
// neutral instead of being painted yellow. Counts are plain dimmed inline numbers, not badges,
// and kind is a small colored icon instead of a filled circular badge.
const SaveConfirmModal: React.FC<Props> = ({ opened, onClose, onConfirm, entries }) => {
    const { t } = useTranslation();
    const draftEntries = entries.filter((e) => !e.liveImpact);
    const liveEntries = entries.filter((e) => e.liveImpact);

    const renderItem = (e: DraftDiffEntry, i: number) => (
        <Group key={i} gap={8} wrap="nowrap" align="flex-start">
            <Box c={`${KIND_COLOR[e.kind]}.7`} style={{ display: 'flex', marginTop: 3 }}>
                {KIND_ICON[e.kind]}
            </Box>
            <Text size="sm" style={{ flex: 1, minWidth: 0 }}>
                <Text span fw={600}>
                    {e.employeeName}
                </Text>{' '}
                · {e.dateLabel} · {e.chipLabel}
            </Text>
        </Group>
    );

    const renderSection = (title: string, count: number, items: DraftDiffEntry[], helper?: string) => (
        <Stack gap={6}>
            <Text size="sm" fw={600}>
                {title} ({count})
            </Text>
            {helper && (
                <Text size="xs" c="dimmed">
                    {helper}
                </Text>
            )}
            <Divider />
            <Stack gap={6}>{items.map(renderItem)}</Stack>
        </Stack>
    );

    return (
        <Modal opened={opened} onClose={onClose} title={t('Save changes')} size="lg">
            {entries.length === 0 ? (
                <Text size="sm" c="dimmed">
                    {t('No changes to save.')}
                </Text>
            ) : (
                <ScrollArea.Autosize mah={420}>
                    <Stack gap="md">
                        {liveEntries.length > 0 && (
                            <Alert variant="light" color="yellow" icon={<LuTriangleAlert size={16} />}>
                                {t(
                                    'These take effect immediately on already-scheduled shifts and notify the affected employees.',
                                )}
                            </Alert>
                        )}

                        {draftEntries.length > 0 &&
                            renderSection(
                                t('Draft changes'),
                                draftEntries.length,
                                draftEntries,
                                t(
                                    'Saved as drafts — they go live only when you finalize. Employees are not notified yet.',
                                ),
                            )}

                        {liveEntries.length > 0 &&
                            renderSection(t('Live shift changes'), liveEntries.length, liveEntries)}
                    </Stack>
                </ScrollArea.Autosize>
            )}
            <Flex justify="flex-end" mt="md" gap="sm">
                <Button variant="default" onClick={onClose}>
                    {t('Cancel')}
                </Button>
                <Button
                    color="teal"
                    leftSection={<LuSave size={16} />}
                    disabled={entries.length === 0}
                    onClick={onConfirm}
                >
                    {t('Save changes')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default SaveConfirmModal;
