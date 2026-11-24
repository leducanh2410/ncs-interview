import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDollarSign, LuSave } from 'react-icons/lu';
import { Box, Button, Flex, LoadingOverlay, Modal, NumberInput, Radio, Text } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { SalaryRequestTypeEnum } from '@/enums/salary_request_type.enum';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DUserSelectRemote from '@/components/DUserSelectRemote';
import { useCreateSalaryRequestMutation, useGetUserSalaryInfo } from '@/pages/manage/salary-request/api';

type Props = {
    opened: boolean;
    onClose: () => void;
};

const SalaryRequestCreateModal = ({ opened, onClose }: Props) => {
    const { t } = useTranslation();
    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            user_id: null,
            amount: 0,
            type: SalaryRequestTypeEnum.TRANSFER.value,
        },
        validate: validatorResolver({
            amount: Validator.number().gt(0),
        }),
    });
    const { data, isPending: isLoadingInfo, refetch } = useGetUserSalaryInfo((form.values.user_id as string) || '');
    const createSalaryRequest = useCreateSalaryRequestMutation();
    const [canSubmit, setCanSubmit] = useState(false);

    useEffect(() => {
        if (!data || !data.data) {
            form.setFieldValue('amount', null);
            setCanSubmit(false);
        } else {
            form.setFieldValue('amount', data.data.amount.max_transfer);
            setCanSubmit(true);
        }
    }, [data]);

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const onSubmit = async (values: Record<string, ParamType>) => {
        try {
            await createSalaryRequest.mutateAsync({
                user_id: values.user_id as string,
                amount: values.amount as number,
                type: values.type as string,
            });
            NotificationHelper.showSuccess(t('Successfully create salary request for user'));
            closeModal();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                FormHelper.setFormError(form, error);
                return;
            }
            let message = t('error|Could not create salary request for user');
            if (error instanceof ApiError) {
                if (error.message === ErrorCodeEnum.SALARY_REQUEST_EXISTS.value) {
                    form.setFieldError('user_id', t('error|Salary request already exists for this user'));
                    return;
                }
            }
            NotificationHelper.showError(message);
        }
    };

    const isTransfer = form.values.type === SalaryRequestTypeEnum.TRANSFER.value;

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Create salary request')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap="8">
                    <DUserSelectRemote
                        label={t('User')}
                        placeholder={t('Search user')}
                        {...form.getInputProps('user_id')}
                    />
                    <Button
                        variant="outline"
                        color="teal"
                        leftSection={<LuDollarSign />}
                        disabled={!form.values.user_id}
                        onClick={async () => {
                            if (!form.values.user_id) return;
                            await refetch();
                        }}
                    >
                        {t('Get salary info')}
                    </Button>
                    <Box pos="relative" mt={16}>
                        <LoadingOverlay
                            visible={isLoadingInfo}
                            zIndex={1000}
                            overlayProps={{ radius: 'sm', blur: 2 }}
                        />
                        {!!data?.data && (
                            <Flex direction="column" gap="8">
                                <Text fw={700} size="xl">
                                    {t('Maximum amount: {{amount}} Yen', {
                                        amount: CommonHelper.formatNumber(data.data.amount.max),
                                    })}
                                </Text>
                                <NumberInput
                                    label={t('Desired amount')}
                                    {...form.getInputProps('amount')}
                                    min={isTransfer ? data.data.amount.min_transfer : data.data.amount.min}
                                    max={isTransfer ? data.data.amount.max_transfer : data.data.amount.max}
                                    thousandSeparator=","
                                    hideControls
                                    step={1000}
                                />
                                <Radio.Group label={t('Receive method')} {...form.getInputProps('type')}>
                                    <Radio
                                        value={SalaryRequestTypeEnum.TRANSFER.value}
                                        label={t('enum|Transfer')}
                                        mb={4}
                                    />
                                    <Radio value={SalaryRequestTypeEnum.CASH.value} label={t('enum|Cash')} />
                                </Radio.Group>
                            </Flex>
                        )}
                    </Box>
                </Flex>
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />} disabled={!canSubmit}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default SalaryRequestCreateModal;
