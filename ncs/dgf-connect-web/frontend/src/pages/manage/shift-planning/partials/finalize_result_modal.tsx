import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuBell, LuCircleCheck, LuCircleX } from 'react-icons/lu';
import { Badge, Button, Divider, Flex, Group, List, Modal, Stack, Text, ThemeIcon } from '@mantine/core';

export type FinalizeDateResult = {
    dateLabel: string;
    committed: boolean;
    notifiedCount: number; // employees notified (delta on re-finalize)
    errors: string[]; // per-cell error reasons when the date fails
};

type Props = {
    opened: boolean;
    onClose: () => void;
    results: FinalizeDateResult[];
};

// Finalize result: per-date atomic outcome. Committed dates list notifications
// sent; failing dates list their per-cell errors to fix and resubmit (spec D18).
const FinalizeResultModal: React.FC<Props> = ({ opened, onClose, results }) => {
    const { t } = useTranslation();
    const committed = results.filter((r) => r.committed);
    const failed = results.filter((r) => !r.committed);

    return (
        <Modal opened={opened} onClose={onClose} title={t('Finalize result')} size="lg">
            <Group gap="xs" mb="md">
                <Badge color="teal" variant="light" leftSection={<LuCircleCheck size={12} />}>
                    {t('{{count}} committed', { count: committed.length })}
                </Badge>
                <Badge color="red" variant="light" leftSection={<LuCircleX size={12} />}>
                    {t('{{count}} failed', { count: failed.length })}
                </Badge>
                <Badge color="blue" variant="light" leftSection={<LuBell size={12} />}>
                    {t('{{count}} notified', {
                        count: committed.reduce((sum, r) => sum + r.notifiedCount, 0),
                    })}
                </Badge>
            </Group>

            <Stack gap="sm">
                {results.map((r, i) => (
                    <div key={i}>
                        {i > 0 && <Divider mb="sm" />}
                        <Group gap={8} mb={4}>
                            <ThemeIcon size="sm" variant="light" color={r.committed ? 'teal' : 'red'}>
                                {r.committed ? <LuCircleCheck size={12} /> : <LuCircleX size={12} />}
                            </ThemeIcon>
                            <Text fw={600} size="sm">
                                {r.dateLabel}
                            </Text>
                            {r.committed ? (
                                <Text size="xs" c="dimmed">
                                    {t('Committed · {{count}} employee(s) notified', { count: r.notifiedCount })}
                                </Text>
                            ) : (
                                <Text size="xs" c="red.7">
                                    {t('Not committed — fix the errors below and resubmit')}
                                </Text>
                            )}
                        </Group>
                        {r.errors.length > 0 && (
                            <List size="sm" spacing={2} withPadding c="red.7">
                                {r.errors.map((e, j) => (
                                    <List.Item key={j}>{e}</List.Item>
                                ))}
                            </List>
                        )}
                    </div>
                ))}
            </Stack>

            <Flex justify="flex-end" mt="md">
                <Button onClick={onClose}>{t('Close')}</Button>
            </Flex>
        </Modal>
    );
};

export default FinalizeResultModal;
