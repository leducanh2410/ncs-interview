import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTriangleAlert } from 'react-icons/lu';
import { Alert, Button, Flex, List, Loader, Modal, Stack, Text } from '@mantine/core';
import CommonHelper from '@/helpers/common.helper';
import { useBackfillCountQuery, useTriggerBackfillMutation } from '@/pages/manage/document-read-config/api';

type Props = {
    configId: number | null;
    opened: boolean;
    onClose: () => void;
};

const BackfillModal = ({ configId, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const { data, isPending: isCountLoading } = useBackfillCountQuery(configId, opened && !!configId);
    const { mutateAsync: triggerBackfill, isPending: isTriggering } = useTriggerBackfillMutation();

    const affectedCount = data?.data?.affected_count ?? 0;

    const onConfirm = async () => {
        if (!configId) return;
        try {
            await triggerBackfill(configId);
            onClose();
        } catch {
            // error handled by mutation
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={t('Backfill document read config')} size="md">
            {isCountLoading ? (
                <Flex justify="center" py="lg">
                    <Loader size="sm" />
                </Flex>
            ) : (
                <Stack gap="md">
                    <Alert color="red" icon={<LuTriangleAlert size={18} />} variant="light">
                        <Stack gap="xs">
                            <Text fw={600}>{t('This action cannot be undone.')}</Text>
                            <List size="sm" spacing="xs">
                                <List.Item>
                                    {t(
                                        'Backfill requires {{formattedCount}} employees to read and confirm this document.',
                                        {
                                            formattedCount: CommonHelper.formatNumber(affectedCount),
                                        },
                                    )}
                                </List.Item>
                                <List.Item>{t('Notifications will be sent to all affected employees.')}</List.Item>
                            </List>
                        </Stack>
                    </Alert>

                    {affectedCount === 0 && (
                        <Text c="dimmed" size="sm">
                            {t('No eligible employees found for backfill.')}
                        </Text>
                    )}

                    <Flex justify="end" gap={8}>
                        <Button color="gray" onClick={onClose}>
                            {t('Cancel')}
                        </Button>
                        <Button color="red" onClick={onConfirm} loading={isTriggering} disabled={affectedCount === 0}>
                            {t('Proceed')}
                        </Button>
                    </Flex>
                </Stack>
            )}
        </Modal>
    );
};

export default BackfillModal;
