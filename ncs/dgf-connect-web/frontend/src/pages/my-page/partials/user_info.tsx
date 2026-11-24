import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Anchor, Button, Flex, Grid, SimpleGrid, TextInput, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { User } from '@/types/models/user';
import { ApiError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { useUpdateUserInfoMutation } from '@/pages/my-page/api';

type Props = {
    user: User;
    onUserChanged: (user: User) => void;
};

const UserInfo = ({ user, onUserChanged }: Props) => {
    const { t } = useTranslation();
    const updateMutation = useUpdateUserInfoMutation();
    const [enabled, setEnabled] = useState(false);

    const form = useForm<Record<string, string>>({
        initialValues: {
            first_name: user.first_name,
            last_name: user.last_name,
            first_name_kana: user.first_name_kana,
            last_name_kana: user.last_name_kana,
        },
        validate: validatorResolver({
            first_name: Validator.string().required(),
            last_name: Validator.string().required(),
            first_name_kana: Validator.string().required(),
            last_name_kana: Validator.string().required(),
        }),
    });

    const onSubmit = async (values: Record<string, string>) => {
        try {
            const response = await updateMutation.mutateAsync(values);
            if (response.data) {
                onUserChanged(response.data);
            }
            NotificationHelper.showSuccess(t('Successfully updated your information'));
            setEnabled(false);
        } catch (e) {
            FormHelper.setFormError(form, e);
            if (e instanceof ApiError) {
                NotificationHelper.showError(t('error|Could not update your information'));
            }
        }
    };

    return (
        <form onSubmit={form.onSubmit(onSubmit)}>
            <Flex align="center" gap={8}>
                <Title order={5} lh="48px">
                    {t('User information')}
                </Title>
                <Anchor onClick={() => setEnabled(true)}>{t('Update')}</Anchor>
            </Flex>
            <Grid mb="lg">
                <Grid.Col span={{ base: 12, md: 8, lg: 6, xl: 4 }}>
                    <SimpleGrid cols={{ base: 1, sm: 2 }} mb="md">
                        <TextInput label={t('Last name')} {...form.getInputProps('last_name')} disabled={!enabled} />
                        <TextInput label={t('First name')} {...form.getInputProps('first_name')} disabled={!enabled} />
                        <TextInput
                            label={t('Last name (kana)')}
                            {...form.getInputProps('last_name_kana')}
                            disabled={!enabled}
                        />
                        <TextInput
                            label={t('First name (kana)')}
                            {...form.getInputProps('first_name_kana')}
                            disabled={!enabled}
                        />
                        <TextInput label={t('Email')} disabled value={user.email} />
                    </SimpleGrid>
                    {enabled && (
                        <Flex gap={8}>
                            <Button type="submit" leftSection={<LuSave />} loading={updateMutation.isPending}>
                                {t('Save')}
                            </Button>
                            <Button variant="default" onClick={() => setEnabled(false)}>
                                {t('Cancel')}
                            </Button>
                        </Flex>
                    )}
                </Grid.Col>
            </Grid>
        </form>
    );
};

export default UserInfo;
