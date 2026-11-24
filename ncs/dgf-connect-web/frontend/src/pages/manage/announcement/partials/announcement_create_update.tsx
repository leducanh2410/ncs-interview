import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuImage, LuLanguages, LuSave, LuTrash2, LuUpload, LuX } from 'react-icons/lu';
import { Button, Checkbox, Flex, Group, Image, Modal, MultiSelect, Switch, Text, TextInput } from '@mantine/core';
import { DateTimePicker } from '@mantine/dates';
import { Dropzone, FileWithPath } from '@mantine/dropzone';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { ParamType } from '@/types/form';
import { AnnouncementWithRelation } from '@/types/models/announcement';
import { BASE_LOCALE, LocaleEnum } from '@/enums/locale.enum';
import { ApiValidationError } from '@/helpers/api.helper';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { masterAtom } from '@/pages/global.state';
import AnnouncementContentEditor from '@/pages/manage/announcement/partials/AnnouncementContentEditor';
import {
    buildFormData,
    buildValidation,
    formInitialValue,
    isEmptyContent,
    toFormValues,
    translationLocales,
} from '@/pages/manage/announcement/partials/announcement_form';
import { useTranslateContentMutation } from '@/pages/translate.api';
import { useCreateAnnouncementMutation, useUpdateAnnouncementMutation } from '@/pages/manage/announcement/api';

const MAX_IMAGE_SIZE = 8 * 1024 ** 2;

const IMAGE_ACCEPT = {
    'image/jpeg': ['.jpg', '.jpeg'],
    'image/png': ['.png'],
    'image/webp': ['.webp'],
};

const AnnouncementCreateUpdate = ({ announcement, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateAnnouncementMutation();
    const updateMutation = useUpdateAnnouncementMutation();
    const translateMutation = useTranslateContentMutation();
    const [masterData] = useAtom(masterAtom);
    const [image, setImage] = useState<File | null>(null);
    const [removeImage, setRemoveImage] = useState(false);

    const form = useForm<Record<string, ParamType>>({
        initialValues: formInitialValue,
        validate: buildValidation(),
    });

    useEffect(() => {
        setImage(null);
        setRemoveImage(false);
        if (!announcement) {
            form.setValues(formInitialValue);
            form.resetDirty();
            return;
        }
        form.setValues(toFormValues(announcement));
        form.resetDirty();
    }, [announcement, opened]);

    const [imageObjectUrl, setImageObjectUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!image) {
            setImageObjectUrl(null);
            return;
        }
        const objectUrl = URL.createObjectURL(image);
        setImageObjectUrl(objectUrl);
        return () => URL.revokeObjectURL(objectUrl);
    }, [image]);

    const previewUrl = imageObjectUrl ?? (!removeImage && announcement?.image_url ? announcement.image_url : null);

    const fieldText = (field: string) => ((form.values[field] as string) || '').trim();
    const hasContent = (field: string) => !isEmptyContent(form.values[field]);

    const onTranslate = async () => {
        const targetLocales = translationLocales.filter(
            (locale) => !fieldText(`title_${locale}`) || !hasContent(`content_${locale}`),
        );
        if (targetLocales.length === 0) {
            NotificationHelper.showInfo(t('All translations are already filled in'));
            return;
        }

        try {
            const response = await translateMutation.mutateAsync({
                items: [
                    { key: 'title', text: fieldText('title') },
                    { key: 'content', text: form.values.content as string },
                ],
                source_locale: LocaleEnum.VIETNAMESE.value,
                target_locales: targetLocales,
                context: 'announcement',
            });
            const translations = response.data?.translations || {};
            for (const locale of targetLocales) {
                const translated = translations[locale];
                if (!translated) {
                    continue;
                }
                const currentTitle = fieldText(`title_${locale}`);
                const currentContent = hasContent(`content_${locale}`);
                const title = currentTitle || translated.title?.trim();
                const content = currentContent ? (form.values[`content_${locale}`] as string) : translated.content;
                if (!title || isEmptyContent(content ?? null)) {
                    continue;
                }
                if (!currentTitle) {
                    form.setFieldValue(`title_${locale}`, title);
                }
                if (!currentContent) {
                    form.setFieldValue(`content_${locale}`, content as string);
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

    const onDrop = (files: FileWithPath[]) => {
        if (files.length === 0) {
            return;
        }
        setImage(files[0]);
        setRemoveImage(false);
    };

    const onRemoveImage = () => {
        setImage(null);
        if (announcement?.image_url) {
            setRemoveImage(true);
        }
    };

    const onSubmit = async (values: Record<string, ParamType>) => {
        const data = buildFormData(values, { image, removeImage, isUpdate: announcement !== null });

        try {
            if (announcement) {
                await updateMutation.mutateAsync({ id: announcement.id.toString(), data });
            } else {
                await createMutation.mutateAsync(data);
            }
            form.reset();
            onClose();
            NotificationHelper.showSuccess(
                announcement ? t('Successfully updated announcement') : t('Successfully created announcement'),
            );
        } catch (error) {
            if (error instanceof ApiValidationError) {
                FormHelper.setFormError(form, error);
                return;
            }
            NotificationHelper.showError(
                announcement ? t('error|Could not update announcement') : t('error|Could not create announcement'),
            );
        }
    };

    const workplaceOptions = masterData.workplaces.map((workplace) => ({
        label: workplace.name,
        value: workplace.id.toString(),
    }));

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            size="lg"
            title={announcement ? t('Edit announcement') : t('Create announcement')}
        >
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap="16" mb="md">
                    <TextInput
                        label={t('Title ({{lang}})', { lang: EnumHelper.getText(LocaleEnum, BASE_LOCALE) })}
                        withAsterisk
                        {...form.getInputProps('title')}
                    />
                    <AnnouncementContentEditor
                        label={t('Content ({{lang}})', { lang: EnumHelper.getText(LocaleEnum, BASE_LOCALE) })}
                        withAsterisk
                        value={form.values.content as string}
                        onChange={(html) => form.setFieldValue('content', html)}
                        error={form.errors.content}
                    />
                    <Button
                        variant="light"
                        leftSection={<LuLanguages />}
                        loading={translateMutation.isPending}
                        disabled={!fieldText('title') || !hasContent('content')}
                        onClick={onTranslate}
                        style={{ alignSelf: 'flex-start' }}
                    >
                        {t('AI translate')}
                    </Button>
                    {translationLocales.map((locale) => (
                        <React.Fragment key={locale}>
                            <Checkbox
                                label={t('Translate to {{lang}}', { lang: EnumHelper.getText(LocaleEnum, locale) })}
                                {...form.getInputProps(`translated_${locale}`, { type: 'checkbox' })}
                            />
                            {form.values[`translated_${locale}`] && (
                                <>
                                    <TextInput
                                        label={t('Title ({{lang}})', { lang: EnumHelper.getText(LocaleEnum, locale) })}
                                        withAsterisk
                                        {...form.getInputProps(`title_${locale}`)}
                                    />
                                    <AnnouncementContentEditor
                                        label={t('Content ({{lang}})', {
                                            lang: EnumHelper.getText(LocaleEnum, locale),
                                        })}
                                        withAsterisk
                                        value={form.values[`content_${locale}`] as string}
                                        onChange={(html) => form.setFieldValue(`content_${locale}`, html)}
                                        error={form.errors[`content_${locale}`]}
                                    />
                                </>
                            )}
                        </React.Fragment>
                    ))}
                    <MultiSelect
                        label={t('Workplace')}
                        placeholder={t('Select workplace')}
                        data={workplaceOptions}
                        searchable
                        withAsterisk
                        {...form.getInputProps('workplace_ids')}
                    />
                    <Group grow align="flex-start">
                        <DateTimePicker
                            label={t('Start time')}
                            valueFormat={t('FORMAT_DATE_WITH_TIME')}
                            withAsterisk
                            clearable
                            {...form.getInputProps('start_at')}
                        />
                        <DateTimePicker
                            label={t('End time')}
                            valueFormat={t('FORMAT_DATE_WITH_TIME')}
                            withAsterisk
                            clearable
                            {...form.getInputProps('end_at')}
                        />
                    </Group>
                    <Switch label={t('Active')} {...form.getInputProps('is_active', { type: 'checkbox' })} />
                    <div>
                        <Text size="sm" fw={500} mb={4}>
                            {t('Image')}
                        </Text>
                        {previewUrl ? (
                            <Flex direction="column" gap="xs" align="flex-start">
                                <Image src={previewUrl} alt={t('Image')} radius="md" mah={240} w="auto" fit="contain" />
                                <Button
                                    variant="light"
                                    color="red"
                                    size="xs"
                                    leftSection={<LuTrash2 size={14} />}
                                    onClick={onRemoveImage}
                                >
                                    {t('Remove image')}
                                </Button>
                            </Flex>
                        ) : (
                            <Dropzone
                                onDrop={onDrop}
                                onReject={() => NotificationHelper.showError(t('error|Unsupported image file'))}
                                maxSize={MAX_IMAGE_SIZE}
                                accept={IMAGE_ACCEPT}
                                multiple={false}
                            >
                                <Group justify="center" gap="md" mih={100} style={{ pointerEvents: 'none' }}>
                                    <Dropzone.Accept>
                                        <LuUpload size={40} color="var(--mantine-color-blue-6)" />
                                    </Dropzone.Accept>
                                    <Dropzone.Reject>
                                        <LuX size={40} color="var(--mantine-color-red-6)" />
                                    </Dropzone.Reject>
                                    <Dropzone.Idle>
                                        <LuImage size={40} color="var(--mantine-color-dimmed)" />
                                    </Dropzone.Idle>
                                    <div>
                                        <Text size="sm" inline>
                                            {t('Drag an image here or click to select')}
                                        </Text>
                                        <Text size="xs" c="dimmed" inline mt={7}>
                                            {t('Supported formats: {{formats}}', { formats: 'jpg, jpeg, png, webp' })}
                                        </Text>
                                    </div>
                                </Group>
                            </Dropzone>
                        )}
                    </div>
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
    );
};

type Props = {
    announcement: AnnouncementWithRelation | null;
    opened: boolean;
    onClose: () => void;
};

export default AnnouncementCreateUpdate;
