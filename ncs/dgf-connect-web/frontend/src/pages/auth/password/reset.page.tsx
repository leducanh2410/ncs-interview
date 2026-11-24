import React from 'react';
import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Button, Flex, Paper, PasswordInput, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import DAlert from '@/components/DAlert';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import { authAtom } from '@/pages/global.state';
import { useResetPasswordMutation } from '@/pages/auth/api';

const PasswordResetPage = () => {
    const { t } = useTranslation();
    const { push, query } = useRouter();
    const [auth] = useAtom(authAtom);
    const mutation = useResetPasswordMutation();
    const form = useForm<Record<string, ParamType>>({
        initialValues: { email: query['email'] as string, password: '', password_confirmation: '' },
        validate: validatorResolver({
            email: Validator.string().email().required(),
            password: Validator.string().required().needConfirmation(),
        }),
    });

    if (auth) {
        push(RouteConfig.HOME.path).catch((e) => console.error(e));
        return;
    }

    const expiredAt = parseInt(query['expired_at'] as string);
    const isExpired = expiredAt && expiredAt < DateHelper.now().unix();
    const isShowForm = !mutation.isSuccess && !isExpired;

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        const data = FormHelper.toParams(values);
        try {
            await mutation.mutateAsync({ ...data, token: query['token'] as string });
        } catch (e) {
            FormHelper.setFormError(form, e);
            let errorMessage = '';
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.E_BAD_REQUEST.value) {
                    errorMessage = t('error|Could not reset your password. Please try again.');
                }
            }
            if (errorMessage) {
                form.setFieldError('general', errorMessage);
            }
        }
    };

    return (
        <Flex align="center" justify="center" w="100vw" h="100vh" bg="gray.0">
            <form onSubmit={form.onSubmit(onFormSubmit)}>
                <Paper p="24" w={360}>
                    <Flex direction="column" gap={24}>
                        <Flex justify="center" direction="column" gap="8">
                            <Title order={5} lh="19px">
                                {t('Reset password')}
                            </Title>
                            <Text c="gray.6" lh="16px">
                                {t('Please reset your password')}
                            </Text>
                        </Flex>
                        {!!form.errors.general && (
                            <DAlert type="danger" title={t('Could not reset password')}>
                                {form.errors.general}
                            </DAlert>
                        )}
                        {isExpired && (
                            <>
                                <DAlert type="danger" title={t('Link expired')}>
                                    {t('Your reset password link has expired. Please request a new one.')}
                                </DAlert>
                                <Button component={Link} href={RouteConfig.AUTH_PASSWORD_FORGOT.path}>
                                    {t('Request new link')}
                                </Button>
                            </>
                        )}
                        {isShowForm && (
                            <>
                                <Flex justify="center" direction="column" gap="16">
                                    <TextInput
                                        w="100%"
                                        label={t('Email')}
                                        placeholder="email@example.com"
                                        {...form.getInputProps('email')}
                                        disabled
                                    />
                                    <Flex direction="column" gap={8}>
                                        <PasswordInput
                                            w="100%"
                                            label={t('Password')}
                                            placeholder={t('Password')}
                                            {...form.getInputProps('password')}
                                            visibilityToggleIcon={DVisibilityToggleIcon}
                                            minLength={6}
                                        />
                                        <PasswordInput
                                            w="100%"
                                            placeholder={t('Confirm password')}
                                            {...form.getInputProps('password_confirmation')}
                                            visibilityToggleIcon={DVisibilityToggleIcon}
                                            minLength={6}
                                        />
                                    </Flex>
                                </Flex>
                                <Button type="submit" loading={mutation.isPending}>
                                    {t('Reset password')}
                                </Button>
                            </>
                        )}
                        {mutation.isSuccess && (
                            <>
                                <DAlert type="success" title={t('Success')}>
                                    {t('Successfully reset your password')}
                                </DAlert>
                                <Button component={Link} href={RouteConfig.AUTH_LOGIN.path}>
                                    {t('Back to login')}
                                </Button>
                            </>
                        )}
                    </Flex>
                </Paper>
            </form>
        </Flex>
    );
};

export default PasswordResetPage;
