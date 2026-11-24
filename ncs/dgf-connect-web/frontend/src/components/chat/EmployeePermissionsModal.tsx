import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Group, Modal, Stack, Switch, Text } from '@mantine/core';
import { ChatEmployeePermissions } from '@/types/models/chat';
import NotificationHelper from '@/helpers/notification.helper';
import { useUpdateEmployeePermissionsMutation } from '@/components/chat/api';

type EmployeePermissionsModalProps = {
    opened: boolean;
    onClose: () => void;
    conversationId: number | null;
    permissions: ChatEmployeePermissions;
};

const EmployeePermissionsModal = ({ opened, onClose, conversationId, permissions }: EmployeePermissionsModalProps) => {
    const { t } = useTranslation();
    const [canMessage, setCanMessage] = useState(true);
    const [canImage, setCanImage] = useState(true);
    const [canFile, setCanFile] = useState(true);
    const mutation = useUpdateEmployeePermissionsMutation(conversationId);

    // Re-sync the toggles to the latest server state each time the modal opens.
    useEffect(() => {
        if (opened) {
            setCanMessage(permissions.employee_can_send_message);
            setCanImage(permissions.employee_can_send_image);
            setCanFile(permissions.employee_can_send_file);
        }
    }, [opened, permissions]);

    const onSave = async () => {
        try {
            await mutation.mutateAsync({
                employee_can_send_message: canMessage,
                employee_can_send_image: canImage,
                employee_can_send_file: canFile,
            });
            NotificationHelper.showSuccess(t('Permissions updated'));
            onClose();
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not update permissions'));
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={t('Employee permissions')} centered>
            <Stack gap="md">
                <Text size="sm" c="dimmed">
                    {t('Control what employees can send in this chat. Admins are never restricted.')}
                </Text>
                <Switch
                    checked={canMessage}
                    onChange={(e) => setCanMessage(e.currentTarget.checked)}
                    label={t('Send text messages')}
                />
                <Switch
                    checked={canImage}
                    onChange={(e) => setCanImage(e.currentTarget.checked)}
                    label={t('Send images')}
                />
                <Switch
                    checked={canFile}
                    onChange={(e) => setCanFile(e.currentTarget.checked)}
                    label={t('Send files')}
                />
                <Group justify="end" mt="sm">
                    <Button variant="default" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button loading={mutation.isPending} onClick={onSave}>
                        {t('Save')}
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );
};

export default EmployeePermissionsModal;
