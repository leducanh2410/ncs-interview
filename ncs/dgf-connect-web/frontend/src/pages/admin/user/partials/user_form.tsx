import { Ref, useEffect, useImperativeHandle, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuExternalLink } from 'react-icons/lu';
import { ActionIcon, Checkbox, Flex, Grid, Loader, Paper, Select, Stack, Text, TextInput } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import JapanPostalCode from 'japan-postal-code';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { GenderEnum } from '@/enums/gender.enum';
import { JapaneseLevelEnum } from '@/enums/japanese_level.enum';
import { NationalityEnum } from '@/enums/nationality.enum';
import { ResidenceStatusEnum } from '@/enums/residence_status.enum';
import { UserPaperStatusEnum } from '@/enums/user_paper_status.enum';
import { ApiError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { getLatestResidenceCard, getLatestResidenceCardFromInfo } from '@/helpers/residence.helper';
import DAlert from '@/components/DAlert';
import PostalCodeFromAddressButton from '@/pages/admin/user/partials/postal_code_from_address_button';
import styles from './user_form.module.css';
import { usePermissions } from '@/hooks/usePermission';
import { UserResponseData, useUpdateUserMutation } from '@/pages/admin/user/api';

export type UserFormHandle = {
    resetForm: () => void;
};

const FieldDisplay = ({ label, value, c }: { label: string; value?: string | null; c?: string }) => (
    <div className={styles.fieldDisplay}>
        <div className={styles.fieldLabel}>{label}</div>
        <Text component="div" fz={14} fw={500} c={c ?? 'dark.8'} lh="xs">
            {value || '—'}
        </Text>
    </div>
);

const getFacebookUrl = (value: string): string => {
    if (value.startsWith('http://') || value.startsWith('https://')) {
        return value;
    }
    return `https://facebook.com/${value}`;
};

const getLineUrl = (value: string): string => {
    if (value.startsWith('http://') || value.startsWith('https://')) {
        return value;
    }
    // LINE profile URLs typically use https://line.me/ti/p/~{username} format
    if (value.startsWith('~')) {
        return `https://line.me/ti/p/${value}`;
    }
    return `https://line.me/ti/p/~${value}`;
};

const formatDate = (date: string | null | undefined): string | undefined => {
    if (!date) return undefined;
    return date.replace(/-/g, '/');
};

const normalizeResidenceField = (value: ParamType | undefined): string | null => {
    if (value === null || value === undefined || value === '') return null;
    if (value instanceof Date) return value.toISOString();
    return String(value);
};

const latestResidenceStatusIsStudyAbroad = (values: Record<string, ParamType>): boolean => {
    const latest = getLatestResidenceCard([
        {
            number: normalizeResidenceField(values.residence_number_1),
            date: normalizeResidenceField(values.residence_expiry_date_1),
            status: normalizeResidenceField(values.residence_status_1),
        },
        {
            number: normalizeResidenceField(values.residence_number_2),
            date: normalizeResidenceField(values.residence_expiry_date_2),
            status: normalizeResidenceField(values.residence_status_2),
        },
        {
            number: normalizeResidenceField(values.residence_number_3),
            date: normalizeResidenceField(values.residence_expiry_date_3),
            status: normalizeResidenceField(values.residence_status_3),
        },
    ]);

    return latest?.status === ResidenceStatusEnum.STUDY_ABROAD.value;
};

const UserForm = ({ id, user, isLoading, isReadOnly, formId, onSaved, onSavingChange, ref }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync, isPending: isUpdating } = useUpdateUserMutation(id);
    const perms = usePermissions(['USER_EDIT', 'USER_PAPER_APPROVE']);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            first_name: '',
            last_name: '',
            first_name_kana: '',
            last_name_kana: '',
            birthday: null,
            gender: '',
            nationality: '',
            phone: '',
            emergency_contact_phone: '',
            email: '',
            postal_code: '',
            prefecture: '',
            address: '',
            registered_location: '',
            staff_no: '',
            japanese_level: '',
            residence_number_1: '',
            residence_expiry_date_1: null,
            residence_status_1: '',
            residence_number_2: '',
            residence_expiry_date_2: null,
            residence_status_2: '',
            residence_number_3: '',
            residence_expiry_date_3: null,
            residence_status_3: '',
            passport_number: '',
            passport_expiry_date: null,
            student_card_expiry_date: null,
            is_household_head: null,
            is_test: false,
            facebook_link: '',
            line_link: '',
        },
        validate: validatorResolver({
            first_name: Validator.string().required(),
            last_name: Validator.string().required(),
            first_name_kana: Validator.string().required(),
            last_name_kana: Validator.string().required(),
            email: Validator.string().required().email(),
            student_card_expiry_date: Validator.string<ParamType>()
                .isSelect()
                .requiredIf((values) => latestResidenceStatusIsStudyAbroad(values)),
        }),
    });

    useImperativeHandle(ref, () => ({
        resetForm: () => form.reset(),
    }));

    useEffect(() => {
        onSavingChange?.(isUpdating);
    }, [isUpdating, onSavingChange]);

    useEffect(() => {
        if (!user) {
            return;
        }

        const info = user.info;
        form.setValues({
            first_name: user.first_name,
            last_name: user.last_name,
            first_name_kana: user.first_name_kana,
            last_name_kana: user.last_name_kana,
            birthday: info?.birthday || null,
            gender: info?.gender || null,
            nationality: info?.nationality || null,
            phone: info?.phone || '',
            emergency_contact_phone: info?.emergency_contact_phone || '',
            email: user.email,
            postal_code: info?.postal_code || '',
            prefecture: info?.prefecture || '',
            address: info?.address || '',
            registered_location: info?.registered_location || '',
            staff_no: user.staff_no || '',
            japanese_level: info?.japanese_level || null,
            residence_number_1: info?.residence_number_1 || '',
            residence_expiry_date_1: info?.residence_expiry_date_1 || null,
            residence_status_1: info?.residence_status_1 || '',
            residence_number_2: info?.residence_number_2 || '',
            residence_expiry_date_2: info?.residence_expiry_date_2 || null,
            residence_status_2: info?.residence_status_2 || '',
            residence_number_3: info?.residence_number_3 || '',
            residence_expiry_date_3: info?.residence_expiry_date_3 || null,
            residence_status_3: info?.residence_status_3 || '',
            passport_number: info?.passport_number || '',
            passport_expiry_date: info?.passport_expiry_date || null,
            student_card_expiry_date: info?.student_card_expiry_date || null,
            is_household_head: info?.is_household_head ?? null,
            is_test: user.is_test ?? false,
            facebook_link: info?.facebook_link || '',
            line_link: info?.line_link || '',
        });
        form.resetDirty();
    }, [user]);

    const isStudyAbroad = useMemo(() => latestResidenceStatusIsStudyAbroad(form.values), [form.values]);

    if (isLoading || !user) {
        return (
            <Flex w="100%" h={200} align="center" justify="center">
                <Loader />
            </Flex>
        );
    }

    const canApprove = perms.USER_PAPER_APPROVE && user.paper_status === UserPaperStatusEnum.WAIT_FOR_APPROVAL.value;
    const canUpdateStaffNo = perms.USER_EDIT && user.paper_status === UserPaperStatusEnum.APPROVED.value;
    const info = user.info;
    const latestResidenceCard = getLatestResidenceCardFromInfo(info);

    return (
        <form
            id={formId}
            onSubmit={form.onSubmit(async (values) => {
                try {
                    const data = FormHelper.toParams(values);
                    // A disabled staff number is not the admin's to send: a form opened before the
                    // approval that assigned one still holds it empty, and would clear it on save
                    if (!canUpdateStaffNo) {
                        delete data.staff_no;
                    }
                    await mutateAsync(data);
                    onSaved?.();
                } catch (error) {
                    if (
                        error instanceof ApiError &&
                        error.message === ErrorCodeEnum.WORKPLACE_TEST_MEMBERSHIP_MISMATCH.value
                    ) {
                        NotificationHelper.showError(
                            t('error|Cannot change test status while workplaces of the opposite type are assigned'),
                        );
                    } else {
                        FormHelper.setFormError(form, error);
                    }
                }
            })}
        >
            {canApprove && (
                <Paper radius="md" p={24} mb={24}>
                    <DAlert type="info" title={t('User has uploaded personal documents')}>
                        {t('User has uploaded personal documents. Please review and approve them.')}
                    </DAlert>
                </Paper>
            )}

            {/* Personal Information */}
            <Paper radius="md" p={24} mb={24}>
                <Flex justify="space-between" align="center" mb={20}>
                    <Text fz={16} fw={700}>
                        {t('Personal Information')}
                    </Text>
                    <Checkbox
                        label={t('Test employee')}
                        disabled={isReadOnly}
                        {...form.getInputProps('is_test', { type: 'checkbox' })}
                    />
                </Flex>
                {isReadOnly ? (
                    <Stack gap={12}>
                        <Grid>
                            <Grid.Col span={6}>
                                <FieldDisplay label={t('Last name')} value={user.last_name} />
                            </Grid.Col>
                            <Grid.Col span={6}>
                                <FieldDisplay label={t('First name')} value={user.first_name} />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={6}>
                                <FieldDisplay label={t('Last name (kana)')} value={user.last_name_kana} />
                            </Grid.Col>
                            <Grid.Col span={6}>
                                <FieldDisplay label={t('First name (kana)')} value={user.first_name_kana} />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={4}>
                                <FieldDisplay label={t('Birthday')} value={formatDate(info?.birthday)} />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <FieldDisplay
                                    label={t('Gender')}
                                    value={info?.gender ? EnumHelper.getText(GenderEnum, info.gender) : undefined}
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <FieldDisplay
                                    label={t('Nationality')}
                                    value={
                                        info?.nationality
                                            ? EnumHelper.getText(NationalityEnum, info.nationality)
                                            : undefined
                                    }
                                />
                            </Grid.Col>
                        </Grid>
                    </Stack>
                ) : (
                    <Stack gap={12}>
                        <Grid>
                            <Grid.Col span={6}>
                                <TextInput withAsterisk label={t('Last name')} {...form.getInputProps('last_name')} />
                            </Grid.Col>
                            <Grid.Col span={6}>
                                <TextInput withAsterisk label={t('First name')} {...form.getInputProps('first_name')} />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={6}>
                                <TextInput
                                    withAsterisk
                                    label={t('Last name (kana)')}
                                    {...form.getInputProps('last_name_kana')}
                                />
                            </Grid.Col>
                            <Grid.Col span={6}>
                                <TextInput
                                    withAsterisk
                                    label={t('First name (kana)')}
                                    {...form.getInputProps('first_name_kana')}
                                />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={4}>
                                <DatePickerInput
                                    label={t('Birthday')}
                                    valueFormat={t('FORMAT_DATE')}
                                    leftSection={<LuCalendar size={14} />}
                                    {...form.getInputProps('birthday')}
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <Select
                                    label={t('Gender')}
                                    data={EnumHelper.toSelectArray(GenderEnum)}
                                    {...form.getInputProps('gender')}
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <Select
                                    label={t('Nationality')}
                                    data={EnumHelper.toSelectArray(NationalityEnum)}
                                    {...form.getInputProps('nationality')}
                                />
                            </Grid.Col>
                        </Grid>
                    </Stack>
                )}
            </Paper>

            {/* Contact Information */}
            <Paper radius="md" p={24} mb={24}>
                <Text fz={16} fw={700} mb={20}>
                    {t('Contact Information')}
                </Text>
                {isReadOnly ? (
                    <Stack gap={12}>
                        <Grid>
                            <Grid.Col span={4}>
                                <FieldDisplay label={t('Phone')} value={info?.phone} />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <FieldDisplay
                                    label={t('Emergency contact phone')}
                                    value={info?.emergency_contact_phone}
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <FieldDisplay label={t('Email')} value={user.email} />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={4}>
                                <div className={styles.fieldDisplay}>
                                    <div className={styles.fieldLabel}>{t('Facebook link')}</div>
                                    <Flex align="center" gap={4}>
                                        <Text component="div" fz={14} fw={500} c="dark.8" lh="xs">
                                            {info?.facebook_link || '—'}
                                        </Text>
                                        {info?.facebook_link && (
                                            <ActionIcon
                                                variant="subtle"
                                                size="xs"
                                                component="a"
                                                href={getFacebookUrl(info.facebook_link)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <LuExternalLink size={12} />
                                            </ActionIcon>
                                        )}
                                    </Flex>
                                </div>
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <div className={styles.fieldDisplay}>
                                    <div className={styles.fieldLabel}>{t('LINE link')}</div>
                                    <Flex align="center" gap={4}>
                                        <Text component="div" fz={14} fw={500} c="dark.8" lh="xs">
                                            {info?.line_link || '—'}
                                        </Text>
                                        {info?.line_link && (
                                            <ActionIcon
                                                variant="subtle"
                                                size="xs"
                                                component="a"
                                                href={getLineUrl(info.line_link)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <LuExternalLink size={12} />
                                            </ActionIcon>
                                        )}
                                    </Flex>
                                </div>
                            </Grid.Col>
                        </Grid>
                    </Stack>
                ) : (
                    <Stack gap={12}>
                        <Grid>
                            <Grid.Col span={4}>
                                <TextInput label={t('Phone')} {...form.getInputProps('phone')} />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <TextInput
                                    label={t('Emergency contact phone')}
                                    {...form.getInputProps('emergency_contact_phone')}
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <TextInput label={t('Email')} {...form.getInputProps('email')} />
                            </Grid.Col>
                        </Grid>
                        <Grid>
                            <Grid.Col span={4}>
                                <TextInput
                                    label={t('Facebook link')}
                                    {...form.getInputProps('facebook_link')}
                                    rightSectionPointerEvents="auto"
                                    rightSection={
                                        form.values.facebook_link ? (
                                            <ActionIcon
                                                variant="subtle"
                                                size="sm"
                                                component="a"
                                                href={getFacebookUrl(form.values.facebook_link as string)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <LuExternalLink size={14} />
                                            </ActionIcon>
                                        ) : undefined
                                    }
                                />
                            </Grid.Col>
                            <Grid.Col span={4}>
                                <TextInput
                                    label={t('LINE link')}
                                    {...form.getInputProps('line_link')}
                                    rightSectionPointerEvents="auto"
                                    rightSection={
                                        form.values.line_link ? (
                                            <ActionIcon
                                                variant="subtle"
                                                size="sm"
                                                component="a"
                                                href={getLineUrl(form.values.line_link as string)}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <LuExternalLink size={14} />
                                            </ActionIcon>
                                        ) : undefined
                                    }
                                />
                            </Grid.Col>
                        </Grid>
                    </Stack>
                )}
            </Paper>

            {/* Address */}
            <Paper radius="md" p={24} mb={24}>
                <Text fz={16} fw={700} mb={20}>
                    {t('Address')}
                </Text>
                {isReadOnly ? (
                    <Grid>
                        <Grid.Col span={2}>
                            <FieldDisplay label={t('Postal code')} value={info?.postal_code} />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <FieldDisplay label={t('Prefecture')} value={info?.prefecture} />
                        </Grid.Col>
                        <Grid.Col span={7}>
                            <FieldDisplay label={t('Address')} value={info?.address} />
                        </Grid.Col>
                    </Grid>
                ) : (
                    <Grid>
                        <Grid.Col span={2}>
                            <TextInput
                                label={t('Postal code')}
                                {...form.getInputProps('postal_code')}
                                onBlur={() => {
                                    JapanPostalCode.get(form.values.postal_code as string, function (data) {
                                        form.setFieldValue('prefecture', data.prefecture);
                                        if (!form.values.address) {
                                            form.setFieldValue('address', data.city + data.area + data.street);
                                        }
                                    });
                                }}
                                rightSectionPointerEvents="auto"
                                rightSection={
                                    <PostalCodeFromAddressButton
                                        address={(form.values.address as string) || ''}
                                        prefecture={(form.values.prefecture as string) || undefined}
                                        onResolved={(postalCode) => form.setFieldValue('postal_code', postalCode)}
                                    />
                                }
                            />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <TextInput label={t('Prefecture')} {...form.getInputProps('prefecture')} />
                        </Grid.Col>
                        <Grid.Col span={7}>
                            <TextInput label={t('Address')} {...form.getInputProps('address')} />
                        </Grid.Col>
                    </Grid>
                )}
            </Paper>

            {/* Work Information */}
            <Paper radius="md" p={24} mb={24}>
                <Text fz={16} fw={700} mb={20}>
                    {t('Work Information')}
                </Text>
                {isReadOnly ? (
                    <Grid>
                        <Grid.Col span={6}>
                            <FieldDisplay label={t('Registered location')} value={info?.registered_location} />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <FieldDisplay label={t('Staff number')} value={user.staff_no} />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <FieldDisplay
                                label={t('Japanese level')}
                                value={
                                    info?.japanese_level
                                        ? EnumHelper.getText(JapaneseLevelEnum, info.japanese_level)
                                        : undefined
                                }
                            />
                        </Grid.Col>
                    </Grid>
                ) : (
                    <Grid>
                        <Grid.Col span={6}>
                            <TextInput
                                label={t('Registered location')}
                                {...form.getInputProps('registered_location')}
                            />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <TextInput
                                label={t('Staff number')}
                                {...form.getInputProps('staff_no')}
                                disabled={!canUpdateStaffNo}
                            />
                        </Grid.Col>
                        <Grid.Col span={3}>
                            <Select
                                label={t('Japanese level')}
                                data={EnumHelper.toSelectArray(JapaneseLevelEnum)}
                                {...form.getInputProps('japanese_level')}
                            />
                        </Grid.Col>
                    </Grid>
                )}
            </Paper>

            {/* Document Information */}
            {user.passport_expiry_alert && (
                <DAlert type="warning" title={t('Card expired or expiring soon')}>
                    {t('Passport has expired or is about to expire, please update')}
                </DAlert>
            )}
            {user.student_card_expiry_alert && (
                <DAlert type="warning" title={t('Card expired or expiring soon')}>
                    {t('Student card has expired or is about to expire, please update')}
                </DAlert>
            )}
            <Paper radius="md" p={24} mb={24}>
                <Text fz={16} fw={700} mb={20}>
                    {t('Document Information')}
                </Text>
                {isReadOnly ? (
                    <Stack gap={20}>
                        <Stack gap={10}>
                            <Text fz={12} fw={600} c="dimmed" tt="uppercase" style={{ letterSpacing: '0.04em' }}>
                                {t('Residence Information')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <FieldDisplay label={t('Residence number')} value={latestResidenceCard?.number} />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <FieldDisplay
                                        label={t('Expiry date')}
                                        value={formatDate(latestResidenceCard?.date)}
                                        c={user.residence_card_expiry_alert ? 'red' : undefined}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <FieldDisplay
                                        label={t('Status')}
                                        value={
                                            latestResidenceCard?.status
                                                ? EnumHelper.getText(ResidenceStatusEnum, latestResidenceCard.status)
                                                : undefined
                                        }
                                    />
                                </Grid.Col>
                            </Grid>
                        </Stack>
                        <Stack gap={10}>
                            <Text fz={12} fw={600} c="dimmed" tt="uppercase" style={{ letterSpacing: '0.04em' }}>
                                {t('Passport & Other')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <FieldDisplay label={t('Passport number')} value={info?.passport_number} />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <FieldDisplay
                                        label={t('Passport expiry date')}
                                        value={formatDate(info?.passport_expiry_date)}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <FieldDisplay
                                        label={t('Student card expiry date')}
                                        value={formatDate(info?.student_card_expiry_date)}
                                    />
                                </Grid.Col>
                            </Grid>
                            <Grid>
                                <Grid.Col span={4}>
                                    <FieldDisplay
                                        label={t('Household Head')}
                                        value={
                                            info?.is_household_head === null || info?.is_household_head === undefined
                                                ? undefined
                                                : info.is_household_head
                                                  ? t('Yes')
                                                  : t('No')
                                        }
                                    />
                                </Grid.Col>
                            </Grid>
                        </Stack>
                    </Stack>
                ) : (
                    <Stack gap={20}>
                        <Stack gap={12}>
                            <Text fz={12} fw={600} c="dimmed" tt="uppercase" style={{ letterSpacing: '0.04em' }}>
                                {t('Residence Information')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Residence number')}
                                        {...form.getInputProps('residence_number_1')}
                                        minLength={12}
                                        maxLength={12}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Residence expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        {...form.getInputProps('residence_expiry_date_1')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        label={t('Residence status')}
                                        data={EnumHelper.toSelectArray(ResidenceStatusEnum)}
                                        {...form.getInputProps('residence_status_1')}
                                    />
                                </Grid.Col>
                            </Grid>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Residence number')}
                                        {...form.getInputProps('residence_number_2')}
                                        minLength={12}
                                        maxLength={12}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Residence expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        {...form.getInputProps('residence_expiry_date_2')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        label={t('Residence status')}
                                        data={EnumHelper.toSelectArray(ResidenceStatusEnum)}
                                        {...form.getInputProps('residence_status_2')}
                                    />
                                </Grid.Col>
                            </Grid>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Residence number')}
                                        {...form.getInputProps('residence_number_3')}
                                        minLength={12}
                                        maxLength={12}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Residence expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        {...form.getInputProps('residence_expiry_date_3')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <Select
                                        label={t('Residence status')}
                                        data={EnumHelper.toSelectArray(ResidenceStatusEnum)}
                                        {...form.getInputProps('residence_status_3')}
                                    />
                                </Grid.Col>
                            </Grid>
                        </Stack>
                        <Stack gap={12}>
                            <Text fz={12} fw={600} c="dimmed" tt="uppercase" style={{ letterSpacing: '0.04em' }}>
                                {t('Passport & Other')}
                            </Text>
                            <Grid>
                                <Grid.Col span={4}>
                                    <TextInput
                                        label={t('Passport number')}
                                        {...form.getInputProps('passport_number')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Passport expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        {...form.getInputProps('passport_expiry_date')}
                                    />
                                </Grid.Col>
                                <Grid.Col span={4}>
                                    <DatePickerInput
                                        label={t('Student card expiry date')}
                                        valueFormat={t('FORMAT_DATE')}
                                        leftSection={<LuCalendar size={14} />}
                                        clearable={!isStudyAbroad}
                                        withAsterisk={isStudyAbroad}
                                        {...form.getInputProps('student_card_expiry_date')}
                                    />
                                </Grid.Col>
                            </Grid>
                            <Checkbox
                                label={t('Household Head')}
                                {...form.getInputProps('is_household_head', { type: 'checkbox' })}
                            />
                        </Stack>
                    </Stack>
                )}
            </Paper>
        </form>
    );
};

type Props = {
    id: string;
    user: UserResponseData | null | undefined;
    isLoading: boolean;
    isReadOnly: boolean;
    formId: string;
    onSaved: () => void;
    onSavingChange: (v: boolean) => void;
    ref?: Ref<UserFormHandle>;
};

export default UserForm;
