import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuLanguages, LuSave } from 'react-icons/lu';
import { Button, ComboboxItem, Flex, Modal, Select, Switch, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { Document } from '@/types/models/document';
import { DocumentRequiredConfig } from '@/types/models/document_required_config';
import { ApiConfig } from '@/configs/api.config';
import { LocaleEnum } from '@/enums/locale.enum';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DSelectRemote from '@/components/DSelectRemote';
import { masterAtom } from '@/pages/global.state';
import { useTranslateContentMutation } from '@/pages/translate.api';
import {
    useCreateDocumentReadConfigMutation,
    useCreateWorkplaceDocumentReadConfigMutation,
    useUpdateDocumentReadConfigMutation,
    useUpdateWorkplaceDocumentReadConfigMutation,
} from '@/pages/manage/document-read-config/api';

type Props = {
    config: DocumentRequiredConfig | null;
    opened: boolean;
    onClose: () => void;
};

const TRANSLATION_LOCALES = [
    LocaleEnum.ENGLISH.value,
    LocaleEnum.JAPANESE.value,
    LocaleEnum.NEPALI.value,
    LocaleEnum.SINHALA.value,
];

const ConfigFormModal = ({ config, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const createMutation = useCreateDocumentReadConfigMutation();
    const createWorkplaceMutation = useCreateWorkplaceDocumentReadConfigMutation();
    const updateMutation = useUpdateDocumentReadConfigMutation();
    const updateWorkplaceMutation = useUpdateWorkplaceDocumentReadConfigMutation();
    const translateMutation = useTranslateContentMutation();

    const form = useForm<Record<string, any>>({
        initialValues: {
            title_vi: '',
            title_en: '',
            title_ja: '',
            title_ne: '',
            title_si: '',
            document_id: '',
            workplace_id: '',
            is_active: true,
        },
        validate: validatorResolver({
            title_vi: Validator.string().required().maxLength(255),
            title_en: Validator.string().required().maxLength(255),
            title_ja: Validator.string().required().maxLength(255),
            title_ne: Validator.string().maxLength(255),
            title_si: Validator.string().maxLength(255),
            document_id: Validator.string().required().isSelect(),
        }),
    });

    useEffect(() => {
        if (config) {
            form.setValues({
                title_vi: config.title_vi,
                title_en: config.title_en,
                title_ja: config.title_ja,
                title_ne: config.title_ne || '',
                title_si: config.title_si || '',
                document_id: config.document_id?.toString() || '',
                workplace_id: config.workplace_id?.toString() || '',
                is_active: config.is_active,
            });
        } else {
            form.setValues({
                title_vi: '',
                title_en: '',
                title_ja: '',
                title_ne: '',
                title_si: '',
                document_id: '',
                workplace_id: '',
                is_active: true,
            });
        }
        form.resetDirty();
    }, [config]);

    const fieldText = (field: string) => ((form.values[field] as string) || '').trim();

    const onTranslate = async () => {
        // Only ask for locales still empty; manual input is never overwritten.
        const targetLocales = TRANSLATION_LOCALES.filter((locale) => !fieldText(`title_${locale}`));
        if (targetLocales.length === 0) {
            NotificationHelper.showInfo(t('All translations are already filled in'));
            return;
        }

        try {
            const response = await translateMutation.mutateAsync({
                items: [{ key: 'title', text: fieldText('title_vi') }],
                source_locale: LocaleEnum.VIETNAMESE.value,
                target_locales: targetLocales,
                context: 'document_read_config',
            });
            const translations = response.data?.translations || {};
            for (const locale of targetLocales) {
                const translatedTitle = translations[locale]?.title;
                if (translatedTitle && !fieldText(`title_${locale}`)) {
                    form.setFieldValue(`title_${locale}`, translatedTitle);
                }
            }
            NotificationHelper.showSuccess(t('Translations filled in. Please review before saving'));
        } catch {
            NotificationHelper.showError(
                t('error|Automatic translation failed. Please try again or enter the translation manually'),
            );
        }
    };

    const onSubmit = async (values: Record<string, any>) => {
        try {
            const payload: Record<string, any> = {
                title_vi: values.title_vi,
                title_en: values.title_en,
                title_ja: values.title_ja,
                title_ne: values.title_ne || null,
                title_si: values.title_si || null,
                document_id: values.document_id ? Number(values.document_id) : null,
                is_active: values.is_active,
            };

            if (config) {
                if (config.workplace_id) {
                    await updateWorkplaceMutation.mutateAsync({
                        workplaceId: config.workplace_id,
                        configId: config.id,
                        data: payload,
                    });
                } else {
                    await updateMutation.mutateAsync({ id: config.id, data: payload });
                }
                NotificationHelper.showSuccess(t('Successfully updated document read config'));
            } else {
                const workplaceId = values.workplace_id ? Number(values.workplace_id) : null;
                if (workplaceId) {
                    await createWorkplaceMutation.mutateAsync({ workplaceId, data: payload });
                } else {
                    await createMutation.mutateAsync(payload);
                }
                NotificationHelper.showSuccess(t('Successfully created document read config'));
            }
            form.reset();
            onClose();
        } catch (error) {
            FormHelper.setFormError(form, error);
        }
    };

    const isProcessing =
        createMutation.isPending ||
        createWorkplaceMutation.isPending ||
        updateMutation.isPending ||
        updateWorkplaceMutation.isPending;
    const documentToOption = (document: Document): ComboboxItem => ({
        value: document.id.toString(),
        label: `${document.id} - ${document.title}`,
    });
    const initialDocumentOptions = config?.document ? [documentToOption(config.document)] : [];

    const workplaceOptions = [
        { value: '', label: t('System-wide') },
        ...masterData.workplaces.map((w) => ({
            value: w.id.toString(),
            label: w.name,
        })),
    ];

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={config ? t('Edit document read config') : t('Create document read config')}
            size="lg"
        >
            <form onSubmit={form.onSubmit(onSubmit)}>
                <TextInput
                    withAsterisk
                    label={t('Title (Vietnamese)')}
                    maxLength={255}
                    {...form.getInputProps('title_vi')}
                    mb={8}
                />
                <Button
                    variant="light"
                    leftSection={<LuLanguages />}
                    loading={translateMutation.isPending}
                    disabled={!fieldText('title_vi')}
                    onClick={onTranslate}
                    mb={8}
                >
                    {t('AI translate')}
                </Button>
                <TextInput
                    withAsterisk
                    label={t('Title (English)')}
                    maxLength={255}
                    {...form.getInputProps('title_en')}
                    mb={8}
                />
                <TextInput
                    withAsterisk
                    label={t('Title (Japanese)')}
                    maxLength={255}
                    {...form.getInputProps('title_ja')}
                    mb={8}
                />
                <TextInput label={t('Title (Nepali)')} maxLength={255} {...form.getInputProps('title_ne')} mb={8} />
                <TextInput label={t('Title (Sinhala)')} maxLength={255} {...form.getInputProps('title_si')} mb={8} />
                <DSelectRemote<Document>
                    withAsterisk
                    label={t('Document')}
                    uri={ApiConfig.ADMIN_DOCUMENT_SEARCH}
                    renderItem={documentToOption}
                    initialOptions={initialDocumentOptions}
                    minSearchLength={1}
                    {...form.getInputProps('document_id')}
                    mb={8}
                />
                {!config && (
                    <Select label={t('Scope')} data={workplaceOptions} {...form.getInputProps('workplace_id')} mb={8} />
                )}
                <Switch label={t('Active')} {...form.getInputProps('is_active', { type: 'checkbox' })} mb={16} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />} loading={isProcessing}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default ConfigFormModal;
