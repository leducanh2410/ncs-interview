import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Flex, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ApiValidationError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import { isUserLockStateChangedError, useEnableUserMutation } from '@/pages/admin/user/api';

const UserUnlockForm = ({ id, closeUnlock }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync: enableUser, isPending } = useEnableUserMutation(id);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            reason: '',
        },
        validate: validatorResolver({
            reason: Validator.string().required().maxLength(255),
        }),
    });
    return (
        <form
            onSubmit={form.onSubmit(async (values) => {
                try {
                    const data = FormHelper.toParams(values);
                    await enableUser(data);
                    notifications.show({
                        color: 'teal',
                        message: t('Successfully unlocked account'),
                    });
                    form.reset();
                    closeUnlock();
                } catch (e) {
                    if (isUserLockStateChangedError(e)) {
                        notifications.show({
                            color: 'yellow',
                            message: t('This account has already been unlocked'),
                        });
                        form.reset();
                        closeUnlock();
                        return;
                    }
                    FormHelper.setFormError(form, e);
                    if (!(e instanceof ApiValidationError)) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not unlock account'),
                        });
                    }
                }
            })}
        >
            <Textarea label={t('Unlock reason')} withAsterisk maxLength={255} {...form.getInputProps('reason')} />
            <Flex justify="flex-end" mt={16} gap={8}>
                <Button
                    variant="filled"
                    color="gray"
                    onClick={() => {
                        form.reset();
                        closeUnlock();
                    }}
                >
                    {t('Cancel')}
                </Button>
                <Button type="submit" variant="filled" loading={isPending}>
                    {t('Unlock account')}
                </Button>
            </Flex>
        </form>
    );
};

type Props = {
    id: string;
    closeUnlock: () => void;
};
export default UserUnlockForm;
