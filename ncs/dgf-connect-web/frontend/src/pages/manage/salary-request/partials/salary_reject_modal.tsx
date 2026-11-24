import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import NotificationHelper from '@/helpers/notification.helper';
import { useRejectSalaryRequestMutation } from '@/pages/manage/salary-request/api';

type Props = {
    id: string;
    opened: boolean;
    onClose: () => void;
};

const SalaryRejectModal = ({ id, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const reject = useRejectSalaryRequestMutation(id);
    const form = useForm<Record<string, string>>({
        initialValues: {
            reason: '',
        },
        validate: validatorResolver({
            reason: Validator.string().required(),
        }),
    });

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const onSubmit = async (values: Record<string, string>) => {
        try {
            await reject.mutateAsync(values.reason);
            NotificationHelper.showSuccess(t('Successfully reject salary request'));
            closeModal();
        } catch (error) {
            NotificationHelper.showError(t('error|Could not reject salary request'));
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Reject salary request')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Textarea label={t('Reject reason')} withAsterisk {...form.getInputProps('reason')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="filled"
                        color="teal"
                        leftSection={<LuSave />}
                        loading={reject.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default SalaryRejectModal;
