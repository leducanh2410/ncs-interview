import React, { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, ComboboxItem, Paper, Select, SimpleGrid, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai/index';
import i18n from '@/locales/i18n';
import { User } from '@/types/models/user';
import { LocaleEnum, UI_SUPPORTED_LOCALES } from '@/enums/locale.enum';
import EnumHelper from '@/helpers/enum.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { authAtom } from '@/pages/global.state';
import { useUpdateSettingMutation } from '@/pages/my-page/api';

const formInitialValues = {
    lang: i18n.language,
};

const SettingsPage = () => {
    const { t } = useTranslation();
    const [auth, setAuth] = useAtom(authAtom);
    const updateSettingsMutation = useUpdateSettingMutation();
    const form = useForm<Record<string, string>>({
        initialValues: formInitialValues,
    });

    useEffect(() => {
        if (!auth?.user) {
            return;
        }

        form.setValues({
            ...formInitialValues,
            ...(auth.user.settings || {}),
        });
    }, [auth?.user]);

    const currentLang = form.values.lang;
    // Employees can be assigned a notification-only locale (ne, si) that the UI itself
    // cannot render (see UI_SUPPORTED_LOCALES). Without this, the Select shows a blank box
    // for them since their value has no matching option. Add it back as a disabled entry so
    // the field reflects their actual setting instead of appearing empty.
    const languageOptions = useMemo(() => {
        const options: ComboboxItem[] = EnumHelper.toSelectArray(LocaleEnum, UI_SUPPORTED_LOCALES);
        if (currentLang && !UI_SUPPORTED_LOCALES.includes(currentLang)) {
            const currentLabel = EnumHelper.getText(LocaleEnum, currentLang);
            if (currentLabel) {
                options.push({
                    value: currentLang,
                    label: `${currentLabel} (${t('Notifications only')})`,
                    disabled: true,
                });
            }
        }
        return options;
    }, [currentLang, t]);

    const onSubmit = async (values: Record<string, string>) => {
        try {
            const response = await updateSettingsMutation.mutateAsync(values);
            if (response.data) {
                setAuth((prev) => {
                    if (!prev) return prev;
                    return {
                        ...prev,
                        user: response.data as User,
                    };
                });
                await i18n.changeLanguage(values.lang);
                NotificationHelper.showSuccess(t('Successfully updated user settings'));
            }
        } catch (e) {
            console.error(e);
            NotificationHelper.showError(t('error|Could not update user settings'));
        }
    };

    return (
        <>
            <Title order={4} lh="48px">
                {t('User settings')}
            </Title>
            <Paper mt="md" p="md">
                <form onSubmit={form.onSubmit(onSubmit)}>
                    <SimpleGrid cols={{ base: 1, sm: 2, md: 3, lg: 4 }} mb="md">
                        <Select label={t('Language')} data={languageOptions} {...form.getInputProps('lang')} />
                    </SimpleGrid>
                    <Button type="submit" leftSection={<LuSave />} loading={updateSettingsMutation.isPending}>
                        {t('Save')}
                    </Button>
                </form>
            </Paper>
        </>
    );
};

export default SettingsPage;
