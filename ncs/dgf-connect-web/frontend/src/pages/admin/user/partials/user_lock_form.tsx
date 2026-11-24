import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Flex, Textarea } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ApiValidationError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import { isUserLockStateChangedError, useDisableUserMutation } from '@/pages/admin/user/api';

const UserLockForm = ({ id, closeLock }: Props) => {
    const { t } = useTranslation();
    const { mutateAsync: disableUser, isPending } = useDisableUserMutation(id);

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
                    await disableUser(data);
                    notifications.show({
                        color: 'teal',
                        message: t('Successfully locked account'),
                    });
                    form.reset();
                    closeLock();
                } catch (e) {
                    if (isUserLockStateChangedError(e)) {
                        notifications.show({
                            color: 'yellow',
                            message: t('This account has already been locked'),
                        });
                        form.reset();
                        closeLock();
                        return;
                    }
                    FormHelper.setFormError(form, e);
                    if (!(e instanceof ApiValidationError)) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not lock account'),
                        });
                    }
                }
            })}
        >
            <Textarea label={t('Lock reason')} withAsterisk maxLength={255} {...form.getInputProps('reason')} />
            <Flex justify="flex-end" mt={16} gap={8}>
                <Button
                    variant="filled"
                    color="gray"
                    onClick={() => {
                        form.reset();
                        closeLock();
                    }}
                >
                    {t('Cancel')}
                </Button>
                <Button type="submit" variant="filled" color="red" loading={isPending}>
                    {t('Lock account')}
                </Button>
            </Flex>
        </form>
    );
};

type Props = {
    id: string;
    closeLock: () => void;
};
export default UserLockForm;
