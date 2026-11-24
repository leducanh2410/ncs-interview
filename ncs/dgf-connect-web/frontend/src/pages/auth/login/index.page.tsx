import { useTranslation } from 'react-i18next';
import Link from 'next/link';
import { useRouter } from 'next/router';
import { Anchor, Button, Checkbox, Flex, Image, Paper, PasswordInput, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import DAlert from '@/components/DAlert';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import { authAtom, tfaAtom } from '@/pages/global.state';
import { useLoginMutation } from '@/pages/auth/api';

const LoginIndexPage = () => {
    const { t } = useTranslation();
    const { push } = useRouter();
    const [auth, setAuth] = useAtom(authAtom);
    const [tfa, setTfa] = useAtom(tfaAtom);
    const loginMutation = useLoginMutation();
    const form = useForm<Record<string, ParamType>>({
        initialValues: { email: '', password: '', remember: false },
        validate: validatorResolver({
            email: Validator.string().email().required(),
            password: Validator.string().required(),
        }),
    });

    if (auth) {
        push(RouteConfig.HOME.path).catch((e) => console.error(e));
        return;
    }

    if (tfa) {
        push(RouteConfig.AUTH_TFA.path).catch((e) => console.error(e));
        return;
    }

    const onFormSubmit = async (values: Record<string, ParamType>) => {
        try {
            const data = FormHelper.toParams(values);
            const response = await loginMutation.mutateAsync(data);
            if (response.data) {
                if ('tfa' in response.data) {
                    setTfa(response.data.tfa);
                } else {
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
            }
        } catch (e) {
            FormHelper.setFormError(form, e);
            let errorMessage = '';
            if (e instanceof ApiError) {
                if (e.message === ErrorCodeEnum.AUTH_INVALID_CREDENTIALS.value) {
                    errorMessage = t('error|Invalid email or password');
                } else if (e.message === ErrorCodeEnum.AUTH_USER_DISABLED.value) {
                    errorMessage = t('error|Your account has been disabled');
                } else if (e.message === ErrorCodeEnum.E_FORBIDDEN.value) {
                    errorMessage = t('error|You are not authorize to access this page');
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
                <Paper p="24" w={400}>
                    <Flex direction="column" gap="24">
                        <Flex justify="center" direction="column" align="center" gap="8">
                            <Image src="/logo.png" w={80} h={80} fit="contain" />
                            <Text size="xl" fw={800}>
                                DGF Connect
                            </Text>
                        </Flex>

                        {form.errors.general && (
                            <DAlert type="danger" title={t('Could not login')}>
                                {form.errors.general}
                            </DAlert>
                        )}
                        <Flex justify="center" direction="column" align="center" gap="16">
                            <TextInput
                                w="100%"
                                placeholder={t('Email')}
                                type="email"
                                {...form.getInputProps('email')}
                            />
                            <PasswordInput
                                w="100%"
                                placeholder={t('Password')}
                                {...form.getInputProps('password')}
                                visibilityToggleIcon={DVisibilityToggleIcon}
                            />
                            <Flex justify="space-between" align="center" w="100%">
                                <Checkbox
                                    label={t('Remember me')}
                                    {...form.getInputProps('remember', { type: 'checkbox' })}
                                />
                                <Anchor component={Link} href={RouteConfig.AUTH_PASSWORD_FORGOT.path}>
                                    {t('Forgot password?')}
                                </Anchor>
                            </Flex>
                        </Flex>
                        <Button type="submit" loading={loginMutation.isPending}>
                            {t('Login')}
                        </Button>
                    </Flex>
                </Paper>
            </form>
        </Flex>
    );
};

export default LoginIndexPage;
