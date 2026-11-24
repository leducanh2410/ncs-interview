import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuLanguages, LuPlus, LuTrash2 } from 'react-icons/lu';
import {
    ActionIcon,
    Box,
    Button,
    Checkbox,
    Flex,
    NumberInput,
    Select,
    SimpleGrid,
    Switch,
    TextInput,
    Textarea,
    Tooltip,
} from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { ParamType } from '@/types/form';
import { Poll, PollChoice } from '@/types/models/poll';
import { User } from '@/types/models/user';
import { ApiConfig } from '@/configs/api.config';
import { LocaleEnum } from '@/enums/locale.enum';
import { PollTargetTypeEnum } from '@/enums/poll_target_type.enum';
import { PollTypeEnum } from '@/enums/poll_type.enum';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { userRenderItem } from '@/components/CommonSearchBox';
import DSelectRemoteMultiple from '@/components/DSelectRemoteMultiple';
import { useGetMasterData } from '@/pages/master.api';
import { useTranslateContentMutation } from '@/pages/translate.api';
import { useCreatePollMutation, useUpdatePollMutation } from '@/pages/manage/poll/api';

type Props = {
    poll: Poll | null;
    onClose: () => void;
};

type PollChoiceFormValue = PollChoice & {
    _key: string;
};

const createPollChoiceKey = () => globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2);

const TRANSLATION_LOCALES = [
    LocaleEnum.ENGLISH.value,
    LocaleEnum.JAPANESE.value,
    LocaleEnum.NEPALI.value,
    LocaleEnum.SINHALA.value,
];

const TITLE_MAX_LENGTH = 255;
const DESCRIPTION_MAX_LENGTH = 1000;
const LABEL_MAX_LENGTH = 255;

/** Truncate by code point, not UTF-16 code unit, so a surrogate pair (e.g. an emoji) is never split. */
const truncateToLength = (text: string, maxLength: number) => Array.from(text).slice(0, maxLength).join('');

const PollForm = ({ poll, onClose }: Props) => {
    const { t } = useTranslation();
    const isEdit = poll !== null;
    const isLocked = isEdit && ((poll?.responses_count ?? 0) > 0 || (poll?.is_active ?? false));
    const { mutateAsync: createPoll, isPending: isCreating } = useCreatePollMutation();
    const { mutateAsync: updatePoll, isPending: isUpdating } = useUpdatePollMutation(poll?.id?.toString() || '');
    const translateMutation = useTranslateContentMutation();
    const { data: masterData } = useGetMasterData(true);
    const [minDate, setMinDate] = useState<Date | undefined>(undefined);
    useEffect(() => {
        setMinDate(new Date());
    }, []);

    const [pollType, setPollType] = useState<string>(poll?.type || 'vote');
    const [targetType, setTargetType] = useState<string>(poll?.target_type || PollTargetTypeEnum.GLOBAL.value);
    const [isMultiple, setIsMultiple] = useState(poll?.is_multiple ?? false);
    const [choices, setChoices] = useState<PollChoiceFormValue[]>(() => {
        if (poll && poll.type === 'feedback') {
            return ((poll.options as PollChoice[]) || []).map((choice) => ({
                ...choice,
                _key: choice.id ? `choice-${choice.id}` : createPollChoiceKey(),
            }));
        }
        return [
            {
                _key: createPollChoiceKey(),
                id: '',
                label_vi: '',
                label_en: '',
                label_ja: '',
                label_ne: '',
                label_si: '',
            },
        ];
    });

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            type: poll?.type || 'vote',
            target_type: poll?.target_type || PollTargetTypeEnum.GLOBAL.value,
            title_vi: poll?.title_vi || '',
            title_en: poll?.title_en || '',
            title_ja: poll?.title_ja || '',
            title_ne: poll?.title_ne || '',
            title_si: poll?.title_si || '',
            description_vi: poll?.description_vi || '',
            description_en: poll?.description_en || '',
            description_ja: poll?.description_ja || '',
            description_ne: poll?.description_ne || '',
            description_si: poll?.description_si || '',
            workplace_id: poll?.workplace_id?.toString() || null,
            target_users: poll?.target_users?.map((u) => u.id.toString()) || [],
            is_required: poll?.is_required ?? true,
            is_recurring: poll?.is_recurring ?? false,
            expires_at: poll?.expires_at ? poll.expires_at.substring(0, 10) : null,
            is_multiple: poll?.is_multiple ?? false,
            max_choice: poll?.max_choice ?? 1,
            options: poll?.type === 'vote' ? (poll.options as number[]).map(String) : [],
        },
    });

    useEffect(() => {
        if (!isMultiple) {
            form.setFieldValue('max_choice', 1);
        }
    }, [isMultiple]);

    const canBeRecurring =
        pollType === PollTypeEnum.FEEDBACK.value && targetType === PollTargetTypeEnum.WORKPLACE.value;
    const isRecurring = canBeRecurring && (form.values.is_recurring as boolean);

    useEffect(() => {
        if (!canBeRecurring && form.values.is_recurring) {
            form.setFieldValue('is_recurring', false);
        }
    }, [canBeRecurring]);

    const onRecurringChange = (checked: boolean) => {
        form.setFieldValue('is_recurring', checked);
        if (checked) {
            form.setFieldValue('is_required', true);
            form.setFieldValue('expires_at', null);
        }
    };

    const workplaceOptions = (masterData?.data?.workplaces || []).map((wp) => ({
        value: wp.id.toString(),
        label: wp.name,
    }));

    const addChoice = () => {
        setChoices([
            ...choices,
            {
                _key: createPollChoiceKey(),
                id: '',
                label_vi: '',
                label_en: '',
                label_ja: '',
                label_ne: '',
                label_si: '',
            },
        ]);
    };

    const removeChoice = (index: number) => {
        setChoices(choices.filter((_, i) => i !== index));
    };

    const updateChoice = (index: number, field: keyof PollChoice, value: string) => {
        const updated = [...choices];
        updated[index] = { ...updated[index], [field]: value };
        setChoices(updated);
    };

    const fieldText = (field: string) => ((form.getValues()[field] as string) || '').trim();
    const choiceLabel = (choice: PollChoiceFormValue, locale: string) =>
        ((choice[`label_${locale}` as keyof PollChoice] as string) || '').trim();

    const onTranslate = async () => {
        const includeChoices = pollType === 'feedback' && !isLocked;
        // Only ask for locales that still have an empty field; manual input is never overwritten.
        const targetLocales = TRANSLATION_LOCALES.filter((locale) => {
            if (!fieldText(`title_${locale}`)) return true;
            if (fieldText('description_vi') && !fieldText(`description_${locale}`)) return true;
            return includeChoices && choices.some((choice) => !choiceLabel(choice, locale));
        });
        if (targetLocales.length === 0) {
            NotificationHelper.showInfo(t('All translations are already filled in'));
            return;
        }

        const items = [{ key: 'title', text: fieldText('title_vi') }];
        const choiceKeysByItemKey: Record<string, string> = {};
        if (fieldText('description_vi')) {
            items.push({ key: 'description', text: fieldText('description_vi') });
        }
        if (includeChoices) {
            choices.forEach((choice, index) => {
                if (choiceLabel(choice, 'vi')) {
                    const itemKey = `choice_${index}`;
                    items.push({ key: itemKey, text: choiceLabel(choice, 'vi') });
                    choiceKeysByItemKey[itemKey] = choice._key;
                }
            });
        }

        try {
            const response = await translateMutation.mutateAsync({
                items,
                source_locale: LocaleEnum.VIETNAMESE.value,
                target_locales: targetLocales,
                context: 'poll',
            });
            const translations = response.data?.translations || {};
            for (const locale of targetLocales) {
                const translated = translations[locale];
                if (!translated) continue;
                if (!fieldText(`title_${locale}`) && translated.title) {
                    form.setFieldValue(`title_${locale}`, truncateToLength(translated.title, TITLE_MAX_LENGTH));
                }
                if (!fieldText(`description_${locale}`) && translated.description) {
                    form.setFieldValue(
                        `description_${locale}`,
                        truncateToLength(translated.description, DESCRIPTION_MAX_LENGTH),
                    );
                }
            }
            if (includeChoices) {
                setChoices((current) =>
                    current.map((choice) => {
                        const updated = { ...choice };
                        const itemKey = Object.keys(choiceKeysByItemKey).find(
                            (key) => choiceKeysByItemKey[key] === choice._key,
                        );
                        for (const locale of targetLocales) {
                            const translatedLabel = itemKey ? translations[locale]?.[itemKey] : undefined;
                            if (translatedLabel && !choiceLabel(choice, locale)) {
                                const key = `label_${locale}` as 'label_en' | 'label_ja' | 'label_ne' | 'label_si';
                                updated[key] = truncateToLength(translatedLabel, LABEL_MAX_LENGTH);
                            }
                        }
                        return updated;
                    }),
                );
            }
            NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
        } catch {
            NotificationHelper.showError(
                t('error|Automatic translation failed. Please try again or enter the translation manually'),
            );
        }
    };

    const handleSubmit = async (isActive: boolean) => {
        const formValidation = form.validate();
        if (formValidation.hasErrors) return;

        const values = form.getValues();

        if (!isLocked) {
            if (pollType === 'vote') {
                const candidateIds = values.options as string[];
                if (!candidateIds || candidateIds.length === 0) {
                    form.setFieldError('options', t('Please select at least one candidate'));
                    return;
                }
            }

            if (pollType === 'feedback') {
                const hasEmpty = choices.some((c) => !c.label_vi || !c.label_en || !c.label_ja);
                if (choices.length === 0 || hasEmpty) {
                    NotificationHelper.showError(t('Please fill in all choice labels'));
                    return;
                }
            }

            if (targetType === PollTargetTypeEnum.WORKPLACE.value && !values.workplace_id) {
                form.setFieldError('workplace_id', t('Please select a workplace'));
                return;
            }

            if (targetType === PollTargetTypeEnum.SPECIFIC_USERS.value) {
                const targetUsers = values.target_users as string[];
                if (!targetUsers || targetUsers.length === 0) {
                    form.setFieldError('target_users', t('Please select at least one user'));
                    return;
                }
            }
        }

        const data: Record<string, any> = {
            type: pollType,
            title_vi: values.title_vi,
            title_en: values.title_en,
            title_ja: values.title_ja,
            title_ne: values.title_ne || null,
            title_si: values.title_si || null,
            description_vi: values.description_vi || null,
            description_en: values.description_en || null,
            description_ja: values.description_ja || null,
            description_ne: values.description_ne || null,
            description_si: values.description_si || null,
            is_required: isRecurring || values.is_required,
            is_recurring: isRecurring,
            expires_at: isRecurring ? null : values.expires_at || null,
        };

        if (!isEdit) {
            data.is_active = isActive;
        }

        if (!isLocked) {
            data.is_multiple = isMultiple;
            data.max_choice = isMultiple ? Number(values.max_choice) : 1;
            data.target_type = targetType;

            if (targetType === 'global') {
                data.workplace_id = null;
                data.target_users = [];
            } else if (targetType === 'workplace') {
                data.workplace_id = values.workplace_id ? Number(values.workplace_id) : null;
                data.target_users = [];
            } else if (targetType === 'specific_users') {
                data.workplace_id = null;
                data.target_users = (values.target_users as string[]).map(Number);
            }

            if (pollType === 'vote') {
                data.options = (values.options as string[]).map(Number);
            } else {
                data.options = choices.map((c) => ({
                    ...(c.id ? { id: c.id } : {}),
                    label_vi: c.label_vi,
                    label_en: c.label_en,
                    label_ja: c.label_ja,
                    label_ne: c.label_ne || null,
                    label_si: c.label_si || null,
                }));
            }
        }

        try {
            if (isEdit) {
                await updatePoll(data);
                NotificationHelper.showSuccess(t('Successfully updated poll'));
            } else {
                await createPoll(data);
                NotificationHelper.showSuccess(t('Successfully created poll'));
            }
            onClose();
        } catch (error) {
            FormHelper.setFormError(form, error);
        }
    };

    return (
        <Box>
            <SimpleGrid cols={2} mb="md">
                <Select
                    withAsterisk
                    label={t('Type')}
                    data={EnumHelper.toSelectArray(PollTypeEnum)}
                    value={pollType}
                    onChange={(val) => {
                        if (val) setPollType(val);
                    }}
                    disabled={isEdit}
                />
                <Select
                    withAsterisk
                    label={t('Scope')}
                    data={[
                        { value: PollTargetTypeEnum.GLOBAL.value, label: t('Global (all workplaces)') },
                        { value: PollTargetTypeEnum.WORKPLACE.value, label: t('Specific Workplace') },
                        { value: PollTargetTypeEnum.SPECIFIC_USERS.value, label: t('Specific Users') },
                    ]}
                    value={targetType}
                    onChange={(val) => {
                        if (val) {
                            setTargetType(val);
                            form.setFieldValue('target_type', val);
                        }
                    }}
                    disabled={isLocked}
                />
            </SimpleGrid>

            {targetType === PollTargetTypeEnum.WORKPLACE.value && (
                <SimpleGrid cols={1} mb="md">
                    <Select
                        withAsterisk
                        label={t('Workplace')}
                        data={workplaceOptions}
                        placeholder={t('Select workplace')}
                        clearable
                        {...form.getInputProps('workplace_id')}
                        disabled={isLocked}
                    />
                </SimpleGrid>
            )}

            {targetType === PollTargetTypeEnum.SPECIFIC_USERS.value && (
                <DSelectRemoteMultiple<User>
                    label={t('Target Users')}
                    uri={ApiConfig.ADMIN_MASTER_USER_SEARCH}
                    renderItem={userRenderItem}
                    withAsterisk
                    mb="md"
                    {...form.getInputProps('target_users')}
                    disabled={isLocked}
                    initialOptions={
                        poll?.target_users?.map((u) => ({
                            value: u.id.toString(),
                            label: u.staff_no ? `${u.name} (${u.staff_no})` : u.name,
                        })) || []
                    }
                />
            )}

            <Flex mb="md">
                <Button
                    variant="light"
                    leftSection={<LuLanguages />}
                    loading={translateMutation.isPending}
                    disabled={!fieldText('title_vi')}
                    onClick={onTranslate}
                >
                    {t('AI translate')}
                </Button>
            </Flex>

            <SimpleGrid cols={3} mb="md">
                <TextInput
                    withAsterisk
                    maxLength={TITLE_MAX_LENGTH}
                    label={t('Title (VI)')}
                    {...form.getInputProps('title_vi')}
                />
                <TextInput
                    withAsterisk
                    maxLength={TITLE_MAX_LENGTH}
                    label={t('Title (EN)')}
                    {...form.getInputProps('title_en')}
                />
                <TextInput
                    withAsterisk
                    maxLength={TITLE_MAX_LENGTH}
                    label={t('Title (JA)')}
                    {...form.getInputProps('title_ja')}
                />
                <TextInput maxLength={TITLE_MAX_LENGTH} label={t('Title (NE)')} {...form.getInputProps('title_ne')} />
                <TextInput maxLength={TITLE_MAX_LENGTH} label={t('Title (SI)')} {...form.getInputProps('title_si')} />
            </SimpleGrid>

            <SimpleGrid cols={3} mb="md">
                <Textarea
                    maxLength={DESCRIPTION_MAX_LENGTH}
                    label={t('Description (VI)')}
                    {...form.getInputProps('description_vi')}
                />
                <Textarea
                    maxLength={DESCRIPTION_MAX_LENGTH}
                    label={t('Description (EN)')}
                    {...form.getInputProps('description_en')}
                />
                <Textarea
                    maxLength={DESCRIPTION_MAX_LENGTH}
                    label={t('Description (JA)')}
                    {...form.getInputProps('description_ja')}
                />
                <Textarea
                    maxLength={DESCRIPTION_MAX_LENGTH}
                    label={t('Description (NE)')}
                    {...form.getInputProps('description_ne')}
                />
                <Textarea
                    maxLength={DESCRIPTION_MAX_LENGTH}
                    label={t('Description (SI)')}
                    {...form.getInputProps('description_si')}
                />
            </SimpleGrid>

            <SimpleGrid cols={3} mb="md">
                <Flex align="center" pt="xl" gap="md">
                    <Checkbox
                        label={t('Required')}
                        checked={isRecurring || (form.values.is_required as boolean)}
                        onChange={(e) => form.setFieldValue('is_required', e.currentTarget.checked)}
                        disabled={isRecurring}
                    />
                    {canBeRecurring && (
                        <Tooltip
                            label={t(
                                'Asked again on the first shift, on every 10th shift after the previous time, and after a gap of more than 30 days',
                            )}
                            multiline
                            maw={320}
                            withArrow
                        >
                            <Checkbox
                                label={t('Recurring')}
                                checked={isRecurring}
                                onChange={(e) => onRecurringChange(e.currentTarget.checked)}
                            />
                        </Tooltip>
                    )}
                </Flex>
                <DatePickerInput
                    label={t('Expires at')}
                    valueFormat={t('FORMAT_DATE')}
                    rightSection={<LuCalendar size={16} />}
                    clearable
                    minDate={minDate}
                    {...form.getInputProps('expires_at')}
                    disabled={isRecurring}
                />
                <Flex direction="column" gap="xs">
                    <Switch
                        label={t('Multiple selection')}
                        checked={isMultiple}
                        onChange={(e) => setIsMultiple(e.currentTarget.checked)}
                        disabled={isLocked}
                        mt="xl"
                    />
                </Flex>
            </SimpleGrid>

            {isMultiple && (
                <NumberInput
                    label={t('Max choices')}
                    min={1}
                    mb="md"
                    w={200}
                    {...form.getInputProps('max_choice')}
                    disabled={isLocked}
                />
            )}

            {pollType === 'vote' && (
                <DSelectRemoteMultiple<User>
                    label={t('Candidates')}
                    uri={ApiConfig.ADMIN_MASTER_USER_SEARCH}
                    renderItem={userRenderItem}
                    withAsterisk
                    mb="md"
                    {...form.getInputProps('options')}
                    disabled={isLocked}
                    initialOptions={
                        poll?.candidate_users?.map((u) => ({
                            value: u.id.toString(),
                            label: u.staff_no ? `${u.name} (${u.staff_no})` : u.name,
                        })) || []
                    }
                />
            )}

            {pollType === 'feedback' && (
                <Box mb="md">
                    {choices.map((choice, index) => (
                        <Flex key={choice._key} gap="sm" mb="xs" align="flex-end">
                            <TextInput
                                label={index === 0 ? t('Label (VI)') : undefined}
                                placeholder={t('Label (VI)')}
                                value={choice.label_vi}
                                onChange={(e) => updateChoice(index, 'label_vi', e.currentTarget.value)}
                                style={{ flex: 1 }}
                                disabled={isLocked}
                                maxLength={LABEL_MAX_LENGTH}
                            />
                            <TextInput
                                label={index === 0 ? t('Label (EN)') : undefined}
                                placeholder={t('Label (EN)')}
                                value={choice.label_en}
                                onChange={(e) => updateChoice(index, 'label_en', e.currentTarget.value)}
                                style={{ flex: 1 }}
                                disabled={isLocked}
                                maxLength={LABEL_MAX_LENGTH}
                            />
                            <TextInput
                                label={index === 0 ? t('Label (JA)') : undefined}
                                placeholder={t('Label (JA)')}
                                value={choice.label_ja}
                                onChange={(e) => updateChoice(index, 'label_ja', e.currentTarget.value)}
                                style={{ flex: 1 }}
                                disabled={isLocked}
                                maxLength={LABEL_MAX_LENGTH}
                            />
                            <TextInput
                                label={index === 0 ? t('Label (NE)') : undefined}
                                placeholder={t('Label (NE)')}
                                value={choice.label_ne || ''}
                                onChange={(e) => updateChoice(index, 'label_ne', e.currentTarget.value)}
                                style={{ flex: 1 }}
                                disabled={isLocked}
                                maxLength={LABEL_MAX_LENGTH}
                            />
                            <TextInput
                                label={index === 0 ? t('Label (SI)') : undefined}
                                placeholder={t('Label (SI)')}
                                value={choice.label_si || ''}
                                onChange={(e) => updateChoice(index, 'label_si', e.currentTarget.value)}
                                style={{ flex: 1 }}
                                disabled={isLocked}
                                maxLength={LABEL_MAX_LENGTH}
                            />
                            {!isLocked && (
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => removeChoice(index)}
                                    disabled={choices.length <= 1}
                                    mb={4}
                                    data-testid="remove-choice-btn"
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            )}
                        </Flex>
                    ))}
                    {!isLocked && (
                        <Button variant="light" size="xs" leftSection={<LuPlus size={14} />} onClick={addChoice}>
                            {t('Add choice')}
                        </Button>
                    )}
                </Box>
            )}

            <Flex justify="flex-end" gap="sm" mt="lg">
                <Button variant="default" onClick={onClose}>
                    {t('Cancel')}
                </Button>
                {isEdit ? (
                    <Button color="teal" onClick={() => handleSubmit(false)} loading={isUpdating}>
                        {t('Save changes')}
                    </Button>
                ) : (
                    <>
                        <Button variant="outline" onClick={() => handleSubmit(false)} loading={isCreating}>
                            {t('Create draft')}
                        </Button>
                        <Button color="teal" onClick={() => handleSubmit(true)} loading={isCreating}>
                            {t('Create and send')}
                        </Button>
                    </>
                )}
            </Flex>
        </Box>
    );
};

export default PollForm;
