import { useTranslation } from 'react-i18next';
import { Button, Flex, Modal } from '@mantine/core';

const DConfirmModal = ({
    opened,
    title,
    message,
    onConfirm,
    onCancel,
    confirmButtonLabel,
    confirmButtonColor,
    cancelButtonLabel,
    isProcessing,
}: Props) => {
    const { t } = useTranslation();
    return (
        <Modal opened={opened} onClose={onCancel} title={title}>
            {message}
            <Flex justify="flex-end" mt={16} gap={8}>
                <Button variant="filled" color="gray" onClick={onCancel}>
                    {cancelButtonLabel || t('Cancel')}
                </Button>
                <Button variant="filled" color={confirmButtonColor} onClick={onConfirm} loading={isProcessing}>
                    {confirmButtonLabel || t('Confirm')}
                </Button>
            </Flex>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    title: string;
    message: string;
    onConfirm: () => void;
    onCancel: () => void;
    confirmButtonLabel?: string;
    confirmButtonColor?: string;
    cancelButtonLabel?: string;
    isProcessing?: boolean;
};

export default DConfirmModal;
