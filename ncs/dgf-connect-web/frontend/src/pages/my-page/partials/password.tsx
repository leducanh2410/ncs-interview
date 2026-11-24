import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Anchor, Box, Button, Flex, Grid, PasswordInput, SimpleGrid, Text, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { User } from '@/types/models/user';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DVisibilityToggleIcon from '@/components/DVisibilityToggleIcon';
import { useChangePasswordMutation } from '@/pages/my-page/api';

type Props = {
    user: User;
    onUserChanged: (user: User) => void;
};

const UserPassword = ({ user, onUserChanged }: Props) => {
    const { t } = useTranslation();
    const updateMutation = useChangePasswordMutation();
    const [enabled, setEnabled] = useState(false);

    const form = useForm<Record<string, string>>({
        initialValues: {
            current_password: '',
            password: '',
            password_confirmation: '',
        },
        validate: validatorResolver({
            current_password: Validator.string().required(),
            password: Validator.string().required().needConfirmation(),
        }),
    });

    const onSubmit = async (values: Record<string, string>) => {
        try {
            const response = await updateMutation.mutateAsync(values);
            if (response.data) {
                onUserChanged(response.data);
            }
            NotificationHelper.showSuccess(t('Successfully updated your password'));
            setEnabled(false);
            form.reset();
        } catch (e) {
            if (e instanceof ApiValidationError) {
                FormHelper.setFormError(form, e);
            } else if (e instanceof ApiError) {
                NotificationHelper.showError(t('error|Could not update your password'));
            }
        }
    };

    return (
        <form onSubmit={form.onSubmit(onSubmit)}>
            <Flex align="center" gap={8}>
                <Title order={5} lh="48px">
                    {t('Password')}
                </Title>
                <Anchor onClick={() => setEnabled(true)}>{t('Update')}</Anchor>
            </Flex>
            {!enabled && (
                <Box mb="md">
                    <Text size="lg">●●●●●●●●</Text>
                </Box>
            )}
            <Grid>
                {enabled && (
                    <Grid.Col span={{ base: 12, md: 8, lg: 6, xl: 4 }}>
                        <SimpleGrid cols={{ base: 1, sm: 2 }} mb="md">
                            <PasswordInput
                                label={t('Current password')}
                                {...form.getInputProps('current_password')}
                                disabled={!enabled}
                                autoComplete="off"
                                visibilityToggleIcon={DVisibilityToggleIcon}
                                minLength={6}
                            />
                            <div />
                            <PasswordInput
                                label={t('New password')}
                                {...form.getInputProps('password')}
                                disabled={!enabled}
                                autoComplete="off"
                                visibilityToggleIcon={DVisibilityToggleIcon}
                                minLength={6}
                            />
                            <PasswordInput
                                label={t('Confirm password')}
                                {...form.getInputProps('password_confirmation')}
                                disabled={!enabled}
                                autoComplete="off"
                                visibilityToggleIcon={DVisibilityToggleIcon}
                                minLength={6}
                            />
                        </SimpleGrid>
                        <Flex gap={8}>
                            <Button type="submit" leftSection={<LuSave />} loading={updateMutation.isPending}>
                                {t('Save')}
                            </Button>
                            <Button variant="default" onClick={() => setEnabled(false)}>
                                {t('Cancel')}
                            </Button>
                        </Flex>
                    </Grid.Col>
                )}
            </Grid>
        </form>
    );
};

export default UserPassword;
