import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuRefreshCw } from 'react-icons/lu';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    Anchor,
    Badge,
    Breadcrumbs,
    Button,
    Checkbox,
    Flex,
    Grid,
    Paper,
    PasswordInput,
    Select,
    Stepper,
    Text,
    TextInput,
    Title,
} from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import JapanPostalCode from 'japan-postal-code';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import RouteConfig from '@/configs/route.config';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { GenderEnum } from '@/enums/gender.enum';
import { JapaneseLevelEnum } from '@/enums/japanese_level.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { ResidenceStatusEnum } from '@/enums/residence_status.enum';
import { ApiError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import PostalCodeFromAddressButton from '@/pages/admin/user/partials/postal_code_from_address_button';
import {
    EmployeeInitResponseData,
    EmployeeInitUser,
    useInitEmployeeMutation,
    useRegisterEmployeeMutation,
    useSetEmployeePasswordMutation,
} from '@/pages/admin/user/api';
import { generateRandomPassword } from '@/utils/password.utils';

const getInitialProfileValues = (): Record<string, ParamType> => ({
    first_name: '',
    last_name: '',
    first_name_kana: '',
    last_name_kana: '',
    phone: '',
    birthday: null,
    gender: null,
    nationality: null,
    postal_code: '',
    prefecture: '',
    address: '',
    japanese_level: null,
    registered_location: '',
    residence_number_1: '',
    residence_expiry_date_1: null,
    residence_status_1: null,
    passport_number: '',
    passport_expiry_date: null,
    facebook_link: '',
    line_link: '',
    is_household_head: false,
});

const buildProfileValues = (user: EmployeeInitUser | null): Record<string, ParamType> => {
    const info = user?.info;

    return {
        ...getInitialProfileValues(),
        first_name: user?.first_name || '',
        last_name: user?.last_name || '',
        first_name_kana: user?.first_name_kana || '',
        last_name_kana: user?.last_name_kana || '',
        phone: info?.phone || '',
        birthday: info?.birthday || null,
        gender: info?.gender || null,
        nationality: info?.nationality || null,
        postal_code: info?.postal_code || '',
        prefecture: info?.prefecture || '',
        address: info?.address || '',
        japanese_level: info?.japanese_level || null,
        registered_location: info?.registered_location || '',
        residence_number_1: info?.residence_number_1 || '',
        residence_expiry_date_1: info?.residence_expiry_date_1 || null,
        residence_status_1: info?.residence_status_1 || null,
        passport_number: info?.passport_number || '',
        passport_expiry_date: info?.passport_expiry_date || null,
        facebook_link: info?.facebook_link || '',
        line_link: info?.line_link || '',
        is_household_head: info?.is_household_head ?? false,
    };
};

const getAutoFilledFields = (user: EmployeeInitUser | null): Set<string> => {
    const fields = new Set<string>();
    const info = user?.info;
    const values = {
        first_name: user?.first_name,
        last_name: user?.last_name,
        first_name_kana: user?.first_name_kana,
        last_name_kana: user?.last_name_kana,
        phone: info?.phone,
        birthday: info?.birthday,
        gender: info?.gender,
        nationality: info?.nationality,
        postal_code: info?.postal_code,
        prefecture: info?.prefecture,
        address: info?.address,
        japanese_level: info?.japanese_level,
        registered_location: info?.registered_location,
        residence_number_1: info?.residence_number_1,
        residence_expiry_date_1: info?.residence_expiry_date_1,
        residence_status_1: info?.residence_status_1,
        passport_number: info?.passport_number,
        passport_expiry_date: info?.passport_expiry_date,
        facebook_link: info?.facebook_link,
        line_link: info?.line_link,
    };

    Object.entries(values).forEach(([field, value]) => {
        if (value !== null && value !== undefined && value !== '') {
            fields.add(field);
        }
    });

    if (info?.is_household_head !== null && info?.is_household_head !== undefined) {
        fields.add('is_household_head');
    }

    return fields;
};

const AdminUserCreatePage = () => {
    const { t } = useTranslation();
    const { push } = useRouter();
    const [activeStep, setActiveStep] = useState(0);
    const [session, setSession] = useState<EmployeeInitResponseData | null>(null);
    const initEmployeeMutation = useInitEmployeeMutation();
    const setEmployeePasswordMutation = useSetEmployeePasswordMutation();
    const registerEmployeeMutation = useRegisterEmployeeMutation();

    const emailForm = useForm<Record<string, ParamType>>({
        initialValues: {
            email: '',
        },
        validate: validatorResolver({
            email: Validator.string().required().email(),
        }),
    });

    const passwordForm = useForm<Record<string, ParamType>>({
        initialValues: {
            password: '12345678',
            password_confirmation: '12345678',
        },
        validate: validatorResolver({
            password: Validator.string().required().needConfirmation(),
            password_confirmation: Validator.string().required(),
        }),
    });

    const profileForm = useForm<Record<string, ParamType>>({
        initialValues: getInitialProfileValues(),
        validate: validatorResolver({
            first_name: Validator.string().required(),
            last_name: Validator.string().required(),
            first_name_kana: Validator.string().required(),
            last_name_kana: Validator.string().required(),
        }),
    });

    const autoFilledFields = getAutoFilledFields(session?.user ?? null);
    const getFieldDescription = (field: string) =>
        autoFilledFields.has(field) ? t('Auto-filled from CrossStaff') : undefined;

    const resetToEmailStep = () => {
        setSession(null);
        setActiveStep(0);
        passwordForm.reset();
        profileForm.setValues(getInitialProfileValues());
        profileForm.resetDirty();
    };

    const handleExpiredSession = () => {
        resetToEmailStep();
        NotificationHelper.showError(t('error|Your employee creation session has expired. Please start again'));
    };

    const onInitSubmit = async (values: Record<string, ParamType>) => {
        try {
            const response = await initEmployeeMutation.mutateAsync(FormHelper.toParams(values));
            if (!response.data) {
                return;
            }

            setSession(response.data);
            passwordForm.reset();
            profileForm.setValues(buildProfileValues(response.data.user));
            profileForm.resetDirty();
            setActiveStep(1);
        } catch (error) {
            FormHelper.setFormError(emailForm, error);
            if (error instanceof ApiError) {
                NotificationHelper.showError(t('error|Could not start employee creation'));
            }
        }
    };

    const onPasswordSubmit = async (values: Record<string, ParamType>) => {
        if (!session) {
            handleExpiredSession();
            return;
        }

        try {
            await setEmployeePasswordMutation.mutateAsync(
                FormHelper.toParams({
                    key: session.key,
                    ...values,
                }),
            );
            setActiveStep(2);
        } catch (error) {
            FormHelper.setFormError(passwordForm, error);
            if (error instanceof ApiError) {
                if (error.message === ErrorCodeEnum.REGISTER_INVALID_SESSION.value) {
                    handleExpiredSession();
                    return;
                }

                NotificationHelper.showError(t('error|Could not save employee password'));
            }
        }
    };

    const onCreateEmployee = async (values: Record<string, ParamType>) => {
        if (!session) {
            handleExpiredSession();
            return;
        }

        try {
            const response = await registerEmployeeMutation.mutateAsync(
                FormHelper.toParams({
                    key: session.key,
                    plain_password: passwordForm.values.password,
                    ...values,
                }),
            );

            if (!response.data) {
                return;
            }

            NotificationHelper.showSuccess(t('Successfully created employee'));
            await push(RouteConfig.ADMIN_USER_LIST.path);
        } catch (error) {
            FormHelper.setFormError(profileForm, error);

            if (!(error instanceof ApiError)) {
                NotificationHelper.showError(t('error|Could not create employee'));
                return;
            }

            if (
                error.message === ErrorCodeEnum.REGISTER_INVALID_SESSION.value ||
                error.message === ErrorCodeEnum.REGISTER_TEMP_USER_NOT_FOUND.value
            ) {
                handleExpiredSession();
                return;
            }

            if (error.message === ErrorCodeEnum.REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS.value) {
                profileForm.setFieldError('residence_number_1', t('error|Residence card number already exists'));
                return;
            }

            if (error.message === ErrorCodeEnum.REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS.value) {
                profileForm.setFieldError('passport_number', t('error|Passport number already exists'));
                return;
            }

            if (error.message === ErrorCodeEnum.REGISTER_STAFF_NO_ALREADY_EXISTS.value) {
                NotificationHelper.showError(t('error|Staff number already exists'));
                return;
            }

            NotificationHelper.showError(t('error|Could not create employee'));
        }
    };

    return (
        <>
            <Breadcrumbs>
                <Anchor component={Link} href={RouteConfig.HOME.path}>
                    {t('Home')}
                </Anchor>
                <Anchor component={Link} href={RouteConfig.ADMIN_USER_LIST.path}>
                    {t('Employee list')}
                </Anchor>
                <Text>{t('Create employee')}</Text>
            </Breadcrumbs>
            <Flex justify="space-between" align="center">
                <Title order={4} lh="48px" my="md">
                    {t('Create employee')}
                </Title>
                <Badge variant="light">{t('Employee')}</Badge>
            </Flex>

            <Paper p="xl">
                <Stepper active={activeStep}>
                    <Stepper.Step label={t('Email')} description={t('Step 1')} />
                    <Stepper.Step label={t('Password')} description={t('Step 2')} />
                    <Stepper.Step label={t('Profile')} description={t('Step 3')} />
                </Stepper>

                {activeStep === 0 && (
                    <form onSubmit={emailForm.onSubmit(onInitSubmit)}>
                        <Paper withBorder p="lg" mt="xl">
                            <Text fw={600} mb="xs">
                                {t('Employee email')}
                            </Text>
                            <Text size="sm" c="dimmed" mb="md">
                                {t('We will search CrossStaff with this email before creating the employee account.')}
                            </Text>
                            <TextInput
                                withAsterisk
                                type="email"
                                label={t('Email')}
                                {...emailForm.getInputProps('email')}
                            />
                            <Flex justify="end" mt="xl">
                                <Button type="submit" loading={initEmployeeMutation.isPending}>
                                    {t('Continue')}
                                </Button>
                            </Flex>
                        </Paper>
                    </form>
                )}

                {activeStep === 1 && session && (
                    <form onSubmit={passwordForm.onSubmit(onPasswordSubmit)}>
                        <Paper withBorder p="lg" mt="xl">
                            <Text fw={600} mb="xs">
                                {t('Set employee password')}
                            </Text>
                            <Text size="sm" c="dimmed" mb="md">
                                {session.email}
                            </Text>
                            <Text size="sm" c="dimmed" mb="md">
                                {t('Default password is')} <code>12345678</code>.{' '}
                                {t('You can change it or generate a random password.')}
                            </Text>
                            <Grid>
                                <Grid.Col span={6}>
                                    <PasswordInput
                                        withAsterisk
                                        label={t('Password')}
                                        minLength={6}
                                        visibilityToggleIcon={DVisibilityToggleIcon}
                                        {...passwordForm.getInputProps('password')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <PasswordInput
                                        withAsterisk
                                        label={t('Confirm password')}
                                        minLength={6}
                                        visibilityToggleIcon={DVisibilityToggleIcon}
                                        {...passwordForm.getInputProps('password_confirmation')}
                                    />
                                </Grid.Col>
                            </Grid>
                            <Flex justify="space-between" mt="xl">
                                <Button type="button" variant="default" onClick={() => setActiveStep(0)}>
                                    {t('Back')}
                                </Button>
                                <Flex gap="sm">
                                    <Button
                                        type="button"
                                        variant="light"
                                        leftSection={<LuRefreshCw size={16} />}
                                        onClick={() => {
                                            const password = generateRandomPassword();
                                            passwordForm.setValues({
                                                password,
                                                password_confirmation: password,
                                            });
                                        }}
                                    >
                                        {t('Generate random password')}
                                    </Button>
                                    <Button type="submit" loading={setEmployeePasswordMutation.isPending}>
                                        {t('Continue')}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Paper>
                    </form>
                )}

                {activeStep === 2 && session && (
                    <form onSubmit={profileForm.onSubmit(onCreateEmployee)}>
                        <Paper withBorder p="lg" mt="xl" mb="lg">
                            <Flex justify="space-between" align="center" gap="md" wrap="wrap">
                                <div>
                                    <Text fw={600}>
                                        {session.user ? t('CrossStaff data found') : t('Manual entry')}
                                    </Text>
                                    <Text size="sm" c="dimmed">
                                        {session.user
                                            ? t('Auto-filled fields can still be edited before creating the employee.')
                                            : t(
                                                  'No CrossStaff data was found. Please enter the employee profile manually.',
                                              )}
                                    </Text>
                                </div>
                                <div>
                                    <Text size="sm" fw={600}>
                                        {t('Email')}
                                    </Text>
                                    <Text size="sm" c="dimmed">
                                        {session.email}
                                    </Text>
                                </div>
                            </Flex>
                        </Paper>

                        <Paper withBorder p="lg" mb="lg">
                            <Text fw={600} mb="md">
                                {t('Basic information')}
                            </Text>
                            <Grid>
                                <Grid.Col span={6}>
                                    <TextInput
                                        withAsterisk
                                        label={t('Last name')}
                                        description={getFieldDescription('last_name')}
                                        {...profileForm.getInputProps('last_name')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        withAsterisk
                                        label={t('First name')}
                                        description={getFieldDescription('first_name')}
                                        {...profileForm.getInputProps('first_name')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        withAsterisk
                                        label={t('Last name (kana)')}
                                        description={getFieldDescription('last_name_kana')}
                                        {...profileForm.getInputProps('last_name_kana')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        withAsterisk
                                        label={t('First name (kana)')}
                                        description={getFieldDescription('first_name_kana')}
                                        {...profileForm.getInputProps('first_name_kana')}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Paper>

                        <Paper withBorder p="lg" mb="lg">
                            <Text fw={600} mb="md">
                                {t('Personal details')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Phone')}
                                        description={getFieldDescription('phone')}
                                        {...profileForm.getInputProps('phone')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Birthday')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        description={getFieldDescription('birthday')}
                                        {...profileForm.getInputProps('birthday')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        clearable
                                        label={t('Gender')}
                                        data={EnumHelper.toSelectArray(GenderEnum)}
                                        description={getFieldDescription('gender')}
                                        {...profileForm.getInputProps('gender')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        clearable
                                        label={t('Nationality')}
                                        data={EnumHelper.toSelectArray(NationalityEnum)}
                                        description={getFieldDescription('nationality')}
                                        {...profileForm.getInputProps('nationality')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        clearable
                                        label={t('Japanese level')}
                                        data={EnumHelper.toSelectArray(JapaneseLevelEnum)}
                                        description={getFieldDescription('japanese_level')}
                                        {...profileForm.getInputProps('japanese_level')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Registered location')}
                                        description={getFieldDescription('registered_location')}
                                        {...profileForm.getInputProps('registered_location')}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Paper>

                        <Paper withBorder p="lg" mb="lg">
                            <Text fw={600} mb="md">
                                {t('Address')}
                            </Text>
                            <Grid>
                                <Grid.Col span={3}>
                                    <TextInput
                                        label={t('Postal code')}
                                        description={getFieldDescription('postal_code')}
                                        {...profileForm.getInputProps('postal_code')}
                                        onBlur={() => {
                                            JapanPostalCode.get(profileForm.values.postal_code as string, (data) => {
                                                profileForm.setFieldValue('prefecture', data.prefecture);
                                                if (!profileForm.values.address) {
                                                    profileForm.setFieldValue(
                                                        'address',
                                                        data.city + data.area + data.street,
                                                    );
                                                }
                                            });
                                        }}
                                        rightSectionPointerEvents="auto"
                                        rightSection={
                                            <PostalCodeFromAddressButton
                                                address={(profileForm.values.address as string) || ''}
                                                prefecture={(profileForm.values.prefecture as string) || undefined}
                                                onResolved={(postalCode) =>
                                                    profileForm.setFieldValue('postal_code', postalCode)
                                                }
                                            />
                                        }
                                    />
                                </Grid.Col>
                                <Grid.Col span={3}>
                                    <TextInput
                                        label={t('Prefecture')}
                                        description={getFieldDescription('prefecture')}
                                        {...profileForm.getInputProps('prefecture')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        label={t('Address')}
                                        description={getFieldDescription('address')}
                                        {...profileForm.getInputProps('address')}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Paper>

                        <Paper withBorder p="lg" mb="lg">
                            <Text fw={600} mb="md">
                                {t('Documents')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Residence card number')}
                                        description={getFieldDescription('residence_number_1')}
                                        {...profileForm.getInputProps('residence_number_1')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Residence card expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        description={getFieldDescription('residence_expiry_date_1')}
                                        {...profileForm.getInputProps('residence_expiry_date_1')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        clearable
                                        label={t('Residence status')}
                                        data={EnumHelper.toSelectArray(ResidenceStatusEnum)}
                                        description={getFieldDescription('residence_status_1')}
                                        {...profileForm.getInputProps('residence_status_1')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        label={t('Passport number')}
                                        description={getFieldDescription('passport_number')}
                                        {...profileForm.getInputProps('passport_number')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <DatePickerInput
                                        label={t('Passport expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        description={getFieldDescription('passport_expiry_date')}
                                        {...profileForm.getInputProps('passport_expiry_date')}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Paper>

                        <Paper withBorder p="lg">
                            <Text fw={600} mb="md">
                                {t('Other')}
                            </Text>
                            <Grid>
                                <Grid.Col span={6}>
                                    <TextInput
                                        label={t('Facebook link')}
                                        description={getFieldDescription('facebook_link')}
                                        {...profileForm.getInputProps('facebook_link')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <TextInput
                                        label={t('LINE link')}
                                        description={getFieldDescription('line_link')}
                                        {...profileForm.getInputProps('line_link')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={6}>
                                    <Checkbox
                                        mt={28}
                                        label={t('Household Head')}
                                        {...profileForm.getInputProps('is_household_head', { type: 'checkbox' })}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Paper>

                        <Flex justify="space-between" mt="xl">
                            <Button type="button" variant="default" onClick={() => setActiveStep(1)}>
                                {t('Back')}
                            </Button>
                            <Button type="submit" loading={registerEmployeeMutation.isPending}>
                                {t('Create employee')}
                            </Button>
                        </Flex>
                    </form>
                )}
            </Paper>
        </>
    );
};

export default AdminUserCreatePage;
