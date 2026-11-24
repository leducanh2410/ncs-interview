import React from 'react';
import { useTranslation } from 'react-i18next';
import { useRouter } from 'next/router';
import { Button, Flex, Image, Paper, Stepper, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { authAtom, tfaAtom } from '@/pages/global.state';
import { useLoginTfaMutation } from '@/pages/auth/api';

const LoginTfaPage = () => {
    const { t } = useTranslation();
    const { push } = useRouter();
    const [auth, setAuth] = useAtom(authAtom);
    const [tfa, setTfa] = useAtom(tfaAtom);
    const loginTfaMutation = useLoginTfaMutation();
    const form = useForm<Record<string, ParamType>>({
        initialValues: { code: '' },
        validate: validatorResolver({
            code: Validator.string().required(),
        }),
    });

    if (auth) {
        push(RouteConfig.HOME.path).catch((e) => console.error(e));
        return;
    }

    if (!tfa) {
        push(RouteConfig.AUTH_LOGIN.path).catch((e) => console.error(e));
        return;
    }

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        try {
            const data = FormHelper.toParams(values);
            const response = await loginTfaMutation.mutateAsync({ key: tfa.key, code: data.code as string });
            if (response.data) {
                localStorage.setItem('access_token', response.data.token);
                setAuth({
                    user: response.data.user,
                    permissions: response.data.permissions,
                    roles: response.data.roles,
                });
                if (response.data.user.settings && response.data.user.settings.lang) {
                    i18n.changeLanguage(response.data.user.settings.lang).catch((e) => console.error(e));
                }
            }
        } catch (e) {
            FormHelper.setFormError(form, e);
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.AUTH_TFA_INVALID_CODE.value) {
                    form.setFieldError('code', t('error|Invalid verification code'));
                }
                if (e.message === ErrorCodeEnum.AUTH_TFA_INVALID_KEY.value) {
                    NotificationHelper.showError(t('error|Your session has expired, please login again'));
                    setTfa(null);
                }
            }
        }
    };

    if (tfa.qr) {
        return (
            <Flex align="center" justify="center" w="100vw" h="100vh" bg="gray.0">
                <form onSubmit={form.onSubmit(onFormSubmit)}>
                    <Paper p="24" w={560}>
                        <Stepper active={1} orientation="vertical">
                            <Stepper.Step
                                label={t('Step 1: Scan QR Code')}
                                icon={1}
                                description={
                                    <Flex direction="column" gap={8} py={8}>
                                        <Text>{t('Scan the QR code with Google Authenticator app or Authy app')}</Text>
                                        <Image src={tfa.qr} w={150} h={150} fit="contain" />
                                    </Flex>
                                }
                            />
                            <Stepper.Step
                                label={t('Step 2: Enter verification code')}
                                icon={2}
                                description={
                                    <Flex direction="column" gap={8} py={8}>
                                        <Text>{t('Enter the 6 digits verification code from the app')}</Text>
                                        <Flex justify="center" direction="column" align="center" gap="16">
                                            <TextInput
                                                autoFocus
                                                w="100%"
                                                placeholder={t('Verification code')}
                                                {...form.getInputProps('code')}
                                            />
                                        </Flex>
                                        <Button type="submit" loading={loginTfaMutation.isPending}>
                                            {t('Login')}
                                        </Button>
                                    </Flex>
                                }
                            />
                        </Stepper>
                    </Paper>
                </form>
            </Flex>
        );
    }

    return (
        <Flex align="center" justify="center" w="100vw" h="100vh" bg="gray.0">
            <form onSubmit={form.onSubmit(onFormSubmit)}>
                <Paper p="24" w={360}>
                    <Flex direction="column" gap="24">
                        <Flex justify="center" direction="column" align="center" gap="8">
                            <Image src="/logo.png" w={80} h={80} fit="contain" />
                            <Text size="xl" fw={800}>
                                DGF Connect
                            </Text>
                        </Flex>

                        <Flex justify="center" direction="column" gap="8">
                            <Text>{t('Enter the 6 digits verification code from the app')}</Text>
                            <TextInput
                                autoFocus
                                w="100%"
                                placeholder={t('Verification code')}
                                {...form.getInputProps('code')}
                            />
                        </Flex>
                        <Button type="submit" loading={loginTfaMutation.isPending}>
                            {t('Login')}
                        </Button>
                    </Flex>
                </Paper>
            </form>
        </Flex>
    );
};

export default LoginTfaPage;
