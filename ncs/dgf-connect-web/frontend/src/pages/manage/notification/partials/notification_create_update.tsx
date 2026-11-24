import React, { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuLanguages, LuSave } from 'react-icons/lu';
import {
    Button,
    CheckIcon,
    Checkbox,
    Flex,
    Modal,
    MultiSelect,
    NumberInput,
    SegmentedControl,
    Select,
    Stack,
    Text,
    TextInput,
    Textarea,
} from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import dayjs from 'dayjs';
import { useAtom } from 'jotai';
import { ParsedUrlQuery } from 'querystring';
import { ParamType } from '@/types/form';
import { NotificationWithRelation } from '@/types/models/notification';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { DEFAULT_NEARBY_RADIUS_KM, GenderTargetEnum, MAX_NEARBY_RADIUS_KM } from '@/enums/gender_target.enum';
import { BASE_LOCALE, LocaleEnum, getTranslationLocales } from '@/enums/locale.enum';
import { NotificationIconEnum, getNotificationIconComponent } from '@/enums/notification_icon.enum';
import { NotificationTargetTypeEnum } from '@/enums/notification_target_type.enum';
import { NotificationTypeEnum, supportsWorkplaceTarget } from '@/enums/notification_type.enum';
import { ApiError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT, DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DTimeInput from '@/components/DTimeInput';
import { masterAtom } from '@/pages/global.state';
import { formInitialValue, validate } from '@/pages/manage/notification/partials/notification_form';
import { useTranslateContentMutation } from '@/pages/translate.api';
import { useCreateNotificationMutation, useUpdateNotificationMutation } from '@/pages/manage/notification/api';
import { useGetActiveShiftsQuery } from '@/pages/manage/shift/api';

const translationLocales = getTranslationLocales();

const NotificationCreateUpdate = ({ notification, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const getShiftQuery = useGetActiveShiftsQuery();
    const createMutation = useCreateNotificationMutation();
    const updateMutation = useUpdateNotificationMutation();
    const translateMutation = useTranslateContentMutation();
    const [masterData] = useAtom(masterAtom);

    const form = useForm<Record<string, ParamType>>({
        initialValues: formInitialValue,
        validate,
    });

    useEffect(() => {
        if (!notification) {
            form.setValues(formInitialValue);
            return;
        }

        const scheduledAt = DateHelper.parse(notification.scheduled_at, DATE_STORE_FORMAT_WITH_TIME);
        const values: Record<string, ParamType> = {
            ...formInitialValue,
            type: notification.type,
            icon: notification.icon,
            title: notification.title || '',
            content: notification.content || '',
            url: notification.url || '',
            target_type: notification.target_type || NotificationTargetTypeEnum.ALL.value,
            workplace_ids: (notification.workplaces || []).map((workplace) => workplace.id.toString()),
            workplace_id: notification.shift?.workplace_id.toString() || null,
            shift_id: notification.shift?.id.toString() || null,
            is_manually_scheduled: notification.is_manually_scheduled === 1,
            date: scheduledAt?.format(DATE_STORE_FORMAT) || null,
            time: scheduledAt?.format('HH:mm') || '',
            // Criteria are absent on notifications created before targeting existed, so each
            // one falls back to the same default the backend applies when reading them.
            gender_target: notification.data?.gender_target || GenderTargetEnum.BOTH.value,
            requires_experience: notification.data?.requires_experience ?? false,
            include_nearby_workplaces: notification.data?.include_nearby_workplaces ?? false,
            nearby_radius_km: notification.data?.nearby_radius_km ?? DEFAULT_NEARBY_RADIUS_KM,
            include_frequent_urgent_group: notification.data?.include_frequent_urgent_group ?? false,
            hot_bonus_amount: notification.data?.hot_bonus_amount ?? null,
        };
        for (const translation of notification.translations) {
            // Fallback rows are filler the backend generated for locales the admin never
            // filled in, not something they actually typed. Loading them into the form as if
            // they were real would resubmit them as genuine translations on the next save.
            if (translation.is_fallback) {
                continue;
            }
            const locale = translation.lang;
            values[`translated_${locale}`] = true;
            values[`title_${locale}`] = translation.title;
            values[`content_${locale}`] = translation.content;
        }
        form.setValues(values);
        form.resetDirty();
    }, [notification]);

    const isRecruitment = form.values.type === NotificationTypeEnum.RECRUITMENT.value;
    const isBankAccountRequest = form.values.type === NotificationTypeEnum.BANK_ACCOUNT_REQUIRED.value;
    const hasWorkplaceTarget = supportsWorkplaceTarget(form.values.type as string);
    const isWorkplaceTargetSelected =
        hasWorkplaceTarget && form.values.target_type === NotificationTargetTypeEnum.WORKPLACE.value;
    const isScheduled = form.values.is_manually_scheduled;
    const workplaceOptions = masterData.workplaces.map((workplace) => ({
        label: workplace.name,
        value: workplace.id.toString(),
    }));

    const fieldText = (field: string) => ((form.values[field] as string) || '').trim();

    const onTranslate = async () => {
        // Only ask for locales that still have an empty field; manual input is never overwritten.
        const targetLocales = translationLocales.filter(
            (locale) => !fieldText(`title_${locale}`) || !fieldText(`content_${locale}`),
        );
        if (targetLocales.length === 0) {
            NotificationHelper.showInfo(t('All translations are already filled in'));
            return;
        }

        try {
            const response = await translateMutation.mutateAsync({
                items: [
                    { key: 'title', text: fieldText('title') },
                    { key: 'content', text: fieldText('content') },
                ],
                source_locale: LocaleEnum.VIETNAMESE.value,
                target_locales: targetLocales,
                context: 'notification',
            });
            const translations = response.data?.translations || {};
            for (const locale of targetLocales) {
                const translated = translations[locale];
                if (!translated) {
                    continue;
                }
                const currentTitle = fieldText(`title_${locale}`);
                const currentContent = fieldText(`content_${locale}`);
                const title = currentTitle || translated.title?.trim();
                const content = currentContent || translated.content?.trim();
                if (!title || !content) {
                    continue;
                }
                if (!currentTitle) {
                    form.setFieldValue(`title_${locale}`, title);
                }
                if (!currentContent) {
                    form.setFieldValue(`content_${locale}`, content);
                }
                form.setFieldValue(`translated_${locale}`, true);
            }
            NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
        } catch {
            NotificationHelper.showError(
                t('error|Automatic translation failed. Please try again or enter the translation manually'),
            );
        }
    };

    const onSubmit = async (values: Record<string, ParamType>) => {
        let data: ParsedUrlQuery = FormHelper.toParams(values);
        delete data.workplace_id;
        if (!hasWorkplaceTarget) {
            delete data.target_type;
            delete data.workplace_ids;
        } else if (data.target_type !== NotificationTargetTypeEnum.WORKPLACE.value) {
            data.workplace_ids = [];
        }
        for (const locale of translationLocales) {
            if (!data[`translated_${locale}`]) {
                delete data[`title_${locale}`];
                delete data[`content_${locale}`];
            }
            delete data[`translated_${locale}`];
        }

        try {
            if (notification) {
                await updateMutation.mutateAsync({
                    id: notification.id.toString(),
                    data,
                });
            } else {
                await createMutation.mutateAsync(data);
            }
            form.reset();
            onClose();
            NotificationHelper.showSuccess(
                notification ? t('Successfully updated notification') : t('Successfully created notification'),
            );
        } catch (error) {
            FormHelper.setFormError(form, error);
            if (error instanceof ApiError) {
                let message = '';
                if (error.message === ErrorCodeEnum.NOTIFICATION_ALREADY_SENT.value) {
                    message = t('error|Could not update. This notification has already been sent');
                }
                if (message) {
                    NotificationHelper.showError(message);
                }
            }
        }
    };

    const shifts = useMemo(() => {
        if (!getShiftQuery.data || !getShiftQuery.data.data) {
            return [];
        }

        let shifts = getShiftQuery.data.data;
        if (form.values.workplace_id) {
            shifts = shifts.filter((shift) => shift.workplace_id.toString() === form.values.workplace_id);
        }

        shifts.sort((a, b) => {
            if (a.date === b.date) {
                return a.workplace_shift.start_time.localeCompare(b.workplace_shift.start_time);
            }

            return a.date.localeCompare(b.date);
        });

        return shifts.map((shift) => {
            const { startTime, endTime } = DateHelper.formatWorkHour(
                shift.workplace_shift.start_time,
                shift.workplace_shift.end_time,
            );
            return {
                label: t('Date: {{date}}. Time: {{time}}', {
                    date: DateHelper.parseAndFormat(shift.date),
                    time: `${startTime} ~ ${endTime}`,
                }),
                value: shift.id.toString(),
            };
        });
    }, [getShiftQuery.data, form.values.workplace_id]);

    return (
        <>
            <Modal
                opened={opened}
                onClose={onClose}
                title={notification ? t('Edit notification') : t('Create notification')}
            >
                <form onSubmit={form.onSubmit(onSubmit)}>
                    <Flex direction="column" gap="16" mb="md">
                        <Select
                            withAsterisk
                            label={t('Type')}
                            data={EnumHelper.toSelectArray(NotificationTypeEnum)}
                            {...form.getInputProps('type')}
                        />
                        {!isRecruitment && (
                            <Select
                                label={t('Icon')}
                                data={EnumHelper.toSelectArray(NotificationIconEnum)}
                                renderOption={({ option, checked }) => {
                                    const Icon = getNotificationIconComponent(option.value);
                                    return (
                                        <Flex align="center" justify="space-between" w="100%">
                                            <Flex gap={8}>
                                                <Icon size={20} color="var(--mantine-color-blue-6)" />
                                                {option.label}
                                            </Flex>
                                            {checked && <CheckIcon size={10} opacity={0.4} />}
                                        </Flex>
                                    );
                                }}
                                {...form.getInputProps('icon')}
                            />
                        )}

                        <TextInput
                            label={t('Title ({{lang}})', { lang: EnumHelper.getText(LocaleEnum, BASE_LOCALE) })}
                            {...form.getInputProps('title')}
                            withAsterisk
                        />
                        <Textarea
                            label={t('Content ({{lang}})', { lang: EnumHelper.getText(LocaleEnum, BASE_LOCALE) })}
                            {...form.getInputProps('content')}
                            withAsterisk
                        />
                        <Button
                            variant="light"
                            leftSection={<LuLanguages />}
                            loading={translateMutation.isPending}
                            disabled={!fieldText('title') || !fieldText('content')}
                            onClick={onTranslate}
                            style={{ alignSelf: 'flex-start' }}
                        >
                            {t('AI translate')}
                        </Button>
                        {translationLocales.map((locale) => {
                            return (
                                <React.Fragment key={locale}>
                                    <Checkbox
                                        label={t('Translate to {{lang}}', {
                                            lang: EnumHelper.getText(LocaleEnum, locale),
                                        })}
                                        {...form.getInputProps(`translated_${locale}`, { type: 'checkbox' })}
                                    />
                                    {form.values[`translated_${locale}`] && (
                                        <>
                                            <TextInput
                                                label={t('Title ({{lang}})', {
                                                    lang: EnumHelper.getText(LocaleEnum, locale),
                                                })}
                                                {...form.getInputProps(`title_${locale}`)}
                                                withAsterisk
                                            />
                                            <Textarea
                                                label={t('Content ({{lang}})', {
                                                    lang: EnumHelper.getText(LocaleEnum, locale),
                                                })}
                                                {...form.getInputProps(`content_${locale}`)}
                                                withAsterisk
                                            />
                                        </>
                                    )}
                                </React.Fragment>
                            );
                        })}
                        {!isRecruitment && !isBankAccountRequest && (
                            <TextInput label={t('Link')} {...form.getInputProps('url')} />
                        )}
                        {isBankAccountRequest && (
                            <Text size="sm" c="dimmed">
                                {t('Tapping this notification opens the salary payout details screen in the app.')}
                            </Text>
                        )}
                        {hasWorkplaceTarget && (
                            <Stack gap={4}>
                                <Text size="sm" fw={500}>
                                    {t('Target')}
                                </Text>
                                <SegmentedControl
                                    data={[
                                        {
                                            value: NotificationTargetTypeEnum.ALL.value,
                                            label: t('All employees'),
                                        },
                                        {
                                            value: NotificationTargetTypeEnum.WORKPLACE.value,
                                            label: t('Selected workplaces'),
                                        },
                                    ]}
                                    value={form.values.target_type as string}
                                    onChange={(value) => form.setFieldValue('target_type', value)}
                                />
                            </Stack>
                        )}
                        {isWorkplaceTargetSelected && (
                            <MultiSelect
                                label={t('Workplace')}
                                placeholder={t('Select workplace')}
                                data={workplaceOptions}
                                searchable
                                clearable
                                withAsterisk
                                {...form.getInputProps('workplace_ids')}
                            />
                        )}
                        {isRecruitment && (
                            <Select
                                label={t('Workplace')}
                                data={workplaceOptions}
                                searchable
                                withAsterisk
                                {...form.getInputProps('workplace_id')}
                            />
                        )}
                        {isRecruitment && (
                            <Select
                                placeholder={t('Select shift')}
                                label={t('Shift')}
                                data={shifts}
                                withAsterisk
                                {...form.getInputProps('shift_id')}
                            />
                        )}
                        {isRecruitment && (
                            <>
                                <Select
                                    label={t('Target gender')}
                                    description={t('Employees who have not recorded a gender are always included.')}
                                    data={EnumHelper.toSelectArray(GenderTargetEnum)}
                                    withAsterisk
                                    {...form.getInputProps('gender_target')}
                                />
                                <Checkbox
                                    label={t('Require experience')}
                                    description={t('Only notify employees who worked a shift in the last 3 months.')}
                                    {...form.getInputProps('requires_experience', { type: 'checkbox' })}
                                />
                                <Checkbox
                                    label={t('Include nearby workplaces')}
                                    {...form.getInputProps('include_nearby_workplaces', { type: 'checkbox' })}
                                />
                                {form.values.include_nearby_workplaces && (
                                    <NumberInput
                                        label={t('Nearby radius (km)')}
                                        min={0}
                                        max={MAX_NEARBY_RADIUS_KM}
                                        withAsterisk
                                        {...form.getInputProps('nearby_radius_km')}
                                    />
                                )}
                                <Checkbox
                                    label={t('Include employees who often take urgent shifts')}
                                    description={t('Reaches this group regardless of distance.')}
                                    {...form.getInputProps('include_frequent_urgent_group', { type: 'checkbox' })}
                                />
                                <NumberInput
                                    label={t('On-the-spot bonus')}
                                    description={t(
                                        'Shown to employees only. Payment is settled directly and is not tracked by the system.',
                                    )}
                                    min={0}
                                    {...form.getInputProps('hot_bonus_amount')}
                                />
                            </>
                        )}
                        <Checkbox
                            label={t('Scheduled send')}
                            {...form.getInputProps('is_manually_scheduled', { type: 'checkbox' })}
                        />
                        {isScheduled && (
                            <>
                                <DatePickerInput
                                    label={t('Date')}
                                    valueFormat={t('FORMAT_DATE')}
                                    rightSection={<LuCalendar size={16} />}
                                    withAsterisk
                                    {...form.getInputProps('date')}
                                    excludeDate={(date) => dayjs(date).isBefore(DateHelper.now().subtract(1, 'day'))}
                                />
                                <DTimeInput withAsterisk label={t('Time')} {...form.getInputProps('time')} />
                            </>
                        )}
                    </Flex>
                    <Flex justify="flex-end" gap="xs">
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
                            leftSection={<LuSave />}
                            loading={createMutation.isPending || updateMutation.isPending}
                        >
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </>
    );
};

type Props = {
    notification: NotificationWithRelation | null;
    opened: boolean;
    onClose: () => void;
};

export default NotificationCreateUpdate;
