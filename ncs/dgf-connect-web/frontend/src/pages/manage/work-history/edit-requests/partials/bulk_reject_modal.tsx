import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, Text, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';

const BulkRejectModal = ({ opened, count, onClose, onSubmit, isProcessing }: Props) => {
    const { t } = useTranslation();
    const form = useForm<Record<string, string>>({
        initialValues: { reject_reason: '' },
        validate: validatorResolver({
            reject_reason: Validator.string().required().maxLength(1000).label('reject reason'),
        }),
    });

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const handleSubmit = async (values: Record<string, string>) => {
        try {
            await onSubmit(values.reject_reason);
            form.reset();
        } catch {
            // onSubmit already reports the failure; leave the typed reason in place to retry.
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Reject selected requests')}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Text mb="md">{t('This reason will be applied to all {{count}} selected requests.', { count })}</Text>
                <Textarea withAsterisk label={t('Reject reason')} {...form.getInputProps('reject_reason')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" variant="filled" color="red" leftSection={<LuSave />} loading={isProcessing}>
                        {t('Reject')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    count: number;
    onClose: () => void;
    onSubmit: (rejectReason: string) => Promise<void>;
    isProcessing?: boolean;
};

export default BulkRejectModal;
