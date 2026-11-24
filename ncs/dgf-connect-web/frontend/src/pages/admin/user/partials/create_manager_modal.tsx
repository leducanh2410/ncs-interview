import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, PasswordInput, Select, SimpleGrid, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { RoleNameEnum, getManagerRoles } from '@/enums/role_name.enum';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import { useCreateManagerMutation } from '@/pages/admin/user/api';

type Props = {
    isOpened: boolean;
    onClose: () => void;
};

const CreateManagerModal = ({ isOpened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateManagerMutation();

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            email: '',
            password: '',
            first_name: '',
            last_name: '',
            first_name_kana: '',
            last_name_kana: '',
            role: null,
        },
        validate: validatorResolver({
            email: Validator.string().required(),
            password: Validator.string().required(),
            first_name: Validator.string().required(),
            last_name: Validator.string().required(),
            first_name_kana: Validator.string().required(),
            last_name_kana: Validator.string().required(),
            role: Validator.string().required().isSelect(),
        }),
    });

    const onSubmit = async (values: Record<string, ParamType>) => {
        try {
            const data = FormHelper.toParams(values);
            const response = await createMutation.mutateAsync(data);
            if (response.data) {
                onClose();
                form.reset();
                NotificationHelper.showSuccess(t('Successfully created manager'));
            }
        } catch (e) {
            FormHelper.setFormError(form, e);
        }
    };

    return (
        <Modal opened={isOpened} onClose={onClose} title={t('Create manager')} size="lg">
            <form onSubmit={form.onSubmit(onSubmit)}>
                <SimpleGrid cols={2} spacing="md">
                    <TextInput
                        withAsterisk
                        label={t('Email')}
                        {...form.getInputProps('email')}
                        autoComplete="off"
                        type="email"
                    />
                    <PasswordInput
                        withAsterisk
                        label={t('Password')}
                        {...form.getInputProps('password')}
                        visibilityToggleIcon={DVisibilityToggleIcon}
                        minLength={6}
                    />
                    <TextInput withAsterisk label={t('Last name')} {...form.getInputProps('last_name')} />
                    <TextInput withAsterisk label={t('First name')} {...form.getInputProps('first_name')} />
                    <TextInput withAsterisk label={t('Last name (kana)')} {...form.getInputProps('last_name_kana')} />
                    <TextInput withAsterisk label={t('First name (kana)')} {...form.getInputProps('first_name_kana')} />
                    <Select
                        withAsterisk
                        label={t('Role')}
                        data={EnumHelper.toSelectArray(RoleNameEnum, getManagerRoles())}
                        {...form.getInputProps('role')}
                    />
                </SimpleGrid>
                <Flex justify="end" mt={24} gap={8}>
                    <Button
                        color="gray"
                        onClick={() => {
                            form.reset();
                            onClose();
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="filled"
                        color="teal"
                        leftSection={<LuSave />}
                        loading={createMutation.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default CreateManagerModal;
