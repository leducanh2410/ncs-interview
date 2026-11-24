import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Box, Button, Flex, NumberInput, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { Config } from '@/types/models/config';
import FormHelper from '@/helpers/form.helper';
import { useUpdateSettingMutation } from '@/pages/admin/configuration/api';

type Props = {
    onClose?: () => void;
    config?: Config | null | undefined;
};

const ConfigurationForm = ({ config, onClose }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync: updateSetting } = useUpdateSettingMutation();
    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            max_shift_per_day: null,
            max_hour_per_week: null,
            salary_per_hour: null,
            login_url: '',
        },
        validate: validatorResolver({
            max_shift_per_day: Validator.number().required(),
            max_hour_per_week: Validator.number().required(),
            salary_per_hour: Validator.number().required(),
            login_url: Validator.string().required().url().maxLength(255),
        }),
    });

    useEffect(() => {
        if (!config) {
            return;
        }

        form.setValues({
            max_shift_per_day: parseInt(config.max_shift_per_day),
            max_hour_per_week: parseInt(config.max_hour_per_week),
            salary_per_hour: parseInt(config.salary_per_hour),
            login_url: config.login_url,
        });
        form.resetDirty();
    }, [config]);

    return (
        <Box flex={1}>
            <form
                onSubmit={form.onSubmit(async (values) => {
                    try {
                        const data = FormHelper.toParams(values, {});
                        await updateSetting(data);
                        notifications.show({
                            title: t('Success'),
                            message: t('Successfully updated configuration'),
                            color: 'teal',
                        });
                        onClose && onClose();
                    } catch (error) {
                        FormHelper.setFormError(form, error);
                    }
                })}
            >
                <Flex direction="column" gap="8">
                    <NumberInput
                        withAsterisk
                        label={t('Max shifts per day')}
                        {...form.getInputProps('max_shift_per_day')}
                    />
                    <NumberInput
                        withAsterisk
                        label={t('Max hours per week')}
                        {...form.getInputProps('max_hour_per_week')}
                    />
                    <NumberInput withAsterisk label={t('Salary per hour')} {...form.getInputProps('salary_per_hour')} />
                    <TextInput
                        withAsterisk
                        label={t('Yamato login URL')}
                        placeholder="https://"
                        {...form.getInputProps('login_url')}
                    />
                    <Text size="xs" c="dimmed">
                        {t('Link shown to employees when they check in or out at a Yamato workshop')}
                    </Text>
                </Flex>
                <Flex justify="flex-end" mt={16} gap={8}>
                    <Button
                        color="gray"
                        onClick={() => {
                            form.reset();
                            onClose && onClose();
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" color="teal" leftSection={<LuSave />}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Box>
    );
};
export default ConfigurationForm;
