import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuRefreshCw } from 'react-icons/lu';
import { Button, Flex, PasswordInput, Text } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import { useResetPasswordMutation } from '@/pages/admin/user/api';
import { generateRandomPassword } from '@/utils/password.utils';

const ResetPasswordForm = ({ id, close }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync: resetPassword, isPending } = useResetPasswordMutation(id);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            password: '12345678',
            password_confirmation: '12345678',
        },
        validate: validatorResolver({
            password: Validator.string().required().needConfirmation(),
            password_confirmation: Validator.string().required(),
        }),
    });

    return (
        <form
            onSubmit={form.onSubmit(async (values) => {
                try {
                    await resetPassword(FormHelper.toParams(values));
                    NotificationHelper.showSuccess(t('Successfully reset password'));
                    form.reset();
                    close();
                } catch (error) {
                    FormHelper.setFormError(form, error);
                    if (!(error instanceof ApiValidationError)) {
                        const message =
                            error instanceof ApiError && typeof error.data === 'string'
                                ? `${t('error|Could not reset password')}: ${error.data}`
                                : t('error|Could not reset password');
                        NotificationHelper.showError(message);
                    }
                }
            })}
        >
            <Text size="sm" c="dimmed" mb="md">
                {t('Default password is')} <code>12345678</code>.{' '}
                {t('You can change it or generate a random password.')}
            </Text>
            <PasswordInput
                withAsterisk
                label={t('Password')}
                minLength={6}
                visibilityToggleIcon={DVisibilityToggleIcon}
                {...form.getInputProps('password')}
            />
            <PasswordInput
                withAsterisk
                mt="md"
                label={t('Confirm password')}
                minLength={6}
                visibilityToggleIcon={DVisibilityToggleIcon}
                {...form.getInputProps('password_confirmation')}
            />
            <Flex justify="space-between" mt={16} gap={8}>
                <Button
                    type="button"
                    variant="light"
                    leftSection={<LuRefreshCw size={16} />}
                    onClick={() => {
                        const password = generateRandomPassword();
                        form.setValues({
                            password,
                            password_confirmation: password,
                        });
                    }}
                >
                    {t('Generate')}
                </Button>
                <Flex gap={8}>
                    <Button
                        variant="filled"
                        color="gray"
                        onClick={() => {
                            form.reset();
                            close();
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={isPending}>
                        {t('Reset password')}
                    </Button>
                </Flex>
            </Flex>
        </form>
    );
};

type Props = {
    id: string;
    close: () => void;
};

export default ResetPasswordForm;
