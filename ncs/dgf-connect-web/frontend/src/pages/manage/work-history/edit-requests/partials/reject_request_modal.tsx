import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';

const RejectRequestModal = ({ opened, onClose, onSubmit, isProcessing }: Props) => {
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
            // onSubmit already reports the failure (e.g. a 409 from a concurrent decision); leave
            // the typed reason in place so the approver doesn't have to retype it.
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Reject edit request')}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Textarea withAsterisk label={t('Reject reason')} {...form.getInputProps('reject_reason')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />} loading={isProcessing}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

type Props = {
    opened: boolean;
    onClose: () => void;
    onSubmit: (rejectReason: string) => Promise<void>;
    isProcessing?: boolean;
};

export default RejectRequestModal;
