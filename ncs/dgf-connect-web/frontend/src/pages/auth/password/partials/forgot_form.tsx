import React, { useCallback, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useRouter } from 'next/router';
import { Button, Flex, Paper, Text, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Turnstile, TurnstileInstance } from '@marsidev/react-turnstile';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import DAlert from '@/components/DAlert';
import { authAtom } from '@/pages/global.state';
import { useRequestResetPasswordMutation } from '@/pages/auth/api';

const PasswordForgotForm = () => {
    const { t, i18n } = useTranslation();
    const { push } = useRouter();
    const [auth] = useAtom(authAtom);
    const mutation = useRequestResetPasswordMutation();
    const turnstileRef = useRef<TurnstileInstance>(null);
    const [turnstileToken, setTurnstileToken] = useState<string | null>(null);
    const form = useForm<Record<string, ParamType>>({
        initialValues: { email: '' },
        validate: validatorResolver({
            email: Validator.string().email().required(),
        }),
    });

    const onFormSubmit = useCallback(
        async (values: Record<string, ParamType>) => {
            if (!turnstileToken) {
                return;
            }
            const data = FormHelper.toParams(values);

            try {
                await mutation.mutateAsync({ email: data.email as string, turnstile_token: turnstileToken });
            } catch (e) {
                turnstileRef.current?.reset();
                setTurnstileToken(null);
                FormHelper.setFormError(form, e);
                if (e instanceof ApiError) {
                    if (e.message === ErrorCodeEnum.AUTH_REQUEST_PASSWORD_RESET_THROTTLED.value) {
                        form.setFieldError('email', t('error|Too many requests, please try again later'));
                    } else if (e.message === ErrorCodeEnum.AUTH_REQUEST_PASSWORD_RESET_INVALID_USER.value) {
                        form.setFieldError('email', t('error|Email not found in the system'));
                    } else if (e.message === ErrorCodeEnum.AUTH_REQUEST_PASSWORD_RESET_CAPTCHA_FAILED.value) {
                        form.setFieldError('email', t('error|Captcha verification failed'));
                    }
                }
            }
        },
        [form, mutation, t, turnstileToken],
    );

    if (auth) {
        push(RouteConfig.HOME.path).catch(console.error);
        return;
    }

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
                                {t('Please enter your email address')}
                            </Text>
                        </Flex>
                        {!mutation.isSuccess && (
                            <Flex direction="column" gap={16}>
                                <TextInput
                                    type="email"
                                    w="100%"
                                    label={t('Email')}
                                    placeholder="email@example.com"
                                    {...form.getInputProps('email')}
                                />
                                <Turnstile
                                    ref={turnstileRef}
                                    siteKey={process.env.NEXT_PUBLIC_TURNSTILE_SITE_KEY as string}
                                    options={{ language: i18n.language }}
                                    onSuccess={setTurnstileToken}
                                    onExpire={() => setTurnstileToken(null)}
                                    onError={() => setTurnstileToken(null)}
                                />
                                <Button type="submit" loading={mutation.isPending} disabled={!turnstileToken}>
                                    {t('Send link')}
                                </Button>
                            </Flex>
                        )}
                        {mutation.isSuccess && (
                            <DAlert type="success" title={t('Email sent')}>
                                {t('We have sent you an email with a link to reset your password.')}
                            </DAlert>
                        )}
                    </Flex>
                </Paper>
            </form>
        </Flex>
    );
};

export default PasswordForgotForm;
