import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal } from '@mantine/core';
import { useForm } from '@mantine/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DUserSelectRemote from '@/components/DUserSelectRemote';
import { useCreateUserFaceMutation } from '@/pages/admin/user-face/api';

type Props = {
    isOpened: boolean;
    onClose: () => void;
};

const CreateUserFaceModal = ({ isOpened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateUserFaceMutation();

    const form = useForm({
        initialValues: {
            user_id: '',
        },
        validate: {
            user_id: (value) => (!value ? t('validation|Please select {{field}}', { field: t('User') }) : null),
        },
    });

    const onSubmit = async (values: typeof form.values) => {
        try {
            await createMutation.mutateAsync({ user_id: Number(values.user_id) });
            NotificationHelper.showSuccess(t('Successfully created user face'));
            form.reset();
            onClose();
        } catch (e) {
            let message = t('Could not create user face');
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.USER_FACE_ALREADY_EXISTS.value) {
                    message = t('error|User face already registered');
                } else if (e.message === ErrorCodeEnum.USER_FACE_NO_APPROVED_PORTRAIT.value) {
                    message = t('error|No approved portrait image found');
                } else if (e.message === ErrorCodeEnum.USER_FACE_REGISTRATION_FAILED.value) {
                    message = t('error|Face registration failed');
                }
            }
            NotificationHelper.showError(message);
        }
    };

    return (
        <Modal opened={isOpened} onClose={onClose} title={t('Create User Face')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <DUserSelectRemote
                    label={t('User')}
                    placeholder={t('Search user')}
                    {...form.getInputProps('user_id')}
                    withAsterisk
                    mb="lg"
                />
                <Flex justify="flex-end" mt="xl" gap="sm">
                    <Button variant="outline" color="gray" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" leftSection={<LuSave />} loading={createMutation.isPending}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default CreateUserFaceModal;
