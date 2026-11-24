import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { useCreateBannedKeywordMutation, useUpdateBannedKeywordMutation } from '../api';
import { Button, Flex, Select, Stack, Switch, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { ChatBannedKeyword } from '@/types/models/chat';

type FormValues = {
    keyword: string;
    locale: string | null;
    action: 'block' | 'mask';
    is_active: boolean;
};

type Props = {
    keyword?: ChatBannedKeyword | null;
    onClose: () => void;
};

const BannedKeywordForm = ({ keyword, onClose }: Props) => {
    const { t } = useTranslation();

    const LOCALE_OPTIONS = [
        { value: '', label: t('All languages') },
        { value: 'ja', label: t('Japanese (ja)') },
        { value: 'vi', label: t('Vietnamese (vi)') },
        { value: 'en', label: t('English (en)') },
    ];
    const createMutation = useCreateBannedKeywordMutation();
    const updateMutation = useUpdateBannedKeywordMutation();

    const form = useForm<FormValues>({
        initialValues: {
            keyword: '',
            locale: null,
            action: 'mask',
            is_active: true,
        },
        validate: {
            keyword: (v) => (v.trim() ? null : t('Keyword is required')),
            action: (v) => (v ? null : t('Action is required')),
        },
    });

    useEffect(() => {
        if (keyword) {
            form.setValues({
                keyword: keyword.keyword,
                locale: keyword.locale,
                action: keyword.action,
                is_active: keyword.is_active,
            });
        } else {
            form.reset();
        }
        form.resetDirty();
    }, [keyword]);

    const handleSubmit = async (values: FormValues) => {
        try {
            const payload = {
                keyword: values.keyword.trim(),
                locale: values.locale || null,
                action: values.action,
                is_active: values.is_active,
            };

            if (keyword) {
                await updateMutation.mutateAsync({ id: keyword.id, data: payload });
                notifications.show({ message: t('Banned keyword updated'), color: 'teal' });
            } else {
                await createMutation.mutateAsync(payload);
                notifications.show({ message: t('Banned keyword added'), color: 'teal' });
            }
            onClose();
        } catch {
            notifications.show({ message: t('error|Could not save banned keyword'), color: 'red' });
        }
    };

    const isLoading = createMutation.isPending || updateMutation.isPending;

    return (
        <form onSubmit={form.onSubmit(handleSubmit)}>
            <Stack gap="md">
                <TextInput
                    label={t('Keyword')}
                    placeholder={t('Enter keyword')}
                    required
                    {...form.getInputProps('keyword')}
                />
                <Select
                    label={t('Language')}
                    data={LOCALE_OPTIONS}
                    value={form.values.locale ?? ''}
                    onChange={(v) => form.setFieldValue('locale', v || null)}
                    clearable={false}
                />
                <Select
                    label={t('Action')}
                    data={[
                        { value: 'mask', label: t('Mask (***)') },
                        { value: 'block', label: t('Block message') },
                    ]}
                    required
                    {...form.getInputProps('action')}
                />
                <Switch
                    label={t('Active')}
                    checked={form.values.is_active}
                    onChange={(e) => form.setFieldValue('is_active', e.currentTarget.checked)}
                />
                <Flex justify="flex-end">
                    <Button type="submit" leftSection={<LuSave size={16} />} loading={isLoading}>
                        {t('Save')}
                    </Button>
                </Flex>
            </Stack>
        </form>
    );
};

export default BannedKeywordForm;
