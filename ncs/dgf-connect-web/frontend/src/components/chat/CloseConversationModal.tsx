import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Group, Modal, Stack, Text, Textarea } from '@mantine/core';
import NotificationHelper from '@/helpers/notification.helper';
import { useCloseAdminChatConversationMutation } from '@/components/chat/api';

type CloseConversationModalProps = {
    opened: boolean;
    onClose: () => void;
    conversationId: number | null;
};

const CloseConversationModal = ({ opened, onClose, conversationId }: CloseConversationModalProps) => {
    const { t } = useTranslation();
    const [reason, setReason] = useState('');
    const mutation = useCloseAdminChatConversationMutation(conversationId);

    useEffect(() => {
        if (opened) {
            setReason('');
        }
    }, [opened]);

    const onConfirm = async () => {
        try {
            await mutation.mutateAsync({
                close_reason: reason.trim() || null,
            });
            NotificationHelper.showSuccess(t('Chat closed'));
            onClose();
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not close chat'));
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={t('Close chat')} centered>
            <Stack gap="md">
                <Text size="sm" c="dimmed">
                    {t('A closed chat becomes read-only. Members can still read past messages.')}
                </Text>
                <Textarea
                    label={t('Reason')}
                    placeholder={t('Optional')}
                    value={reason}
                    onChange={(e) => setReason(e.currentTarget.value)}
                    maxLength={255}
                    autosize
                    minRows={2}
                    maxRows={4}
                />
                <Group justify="end" mt="sm">
                    <Button variant="default" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button color="red" loading={mutation.isPending} onClick={onConfirm}>
                        {t('Close chat')}
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );
};

export default CloseConversationModal;
