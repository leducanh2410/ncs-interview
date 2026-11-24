import React from 'react';
import { useTranslation } from 'react-i18next';
import { Button, Checkbox, Flex, Modal, NumberInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ShiftDetail } from '@/types/models/shift';
import NotificationHelper from '@/helpers/notification.helper';
import WeeklyHourLimitHelper from '@/helpers/weekly_hour_limit.helper';
import { useUpdateShiftConfigMutation } from '@/pages/manage/shift/api';

type ConfigFormValues = {
    fixed_total: number;
    temp_total: number;
    max_registered_temp: number | string;
    break_minutes: number | string;
    is_overtime_by_duration: boolean;
};

type Props = {
    isOpen: boolean;
    onClose: () => void;
    shift: ShiftDetail;
};

const ConfigModal = ({ isOpen, onClose, shift }: Props) => {
    const { t } = useTranslation();
    const updateConfigMutation = useUpdateShiftConfigMutation(shift.id.toString());

    const form = useForm<ConfigFormValues>({
        initialValues: {
            fixed_total: shift.fixed_total,
            temp_total: shift.temp_total,
            max_registered_temp: shift.max_registered_temp ?? '',
            break_minutes: shift.break_minutes ?? '',
            is_overtime_by_duration: shift.is_overtime_by_duration ?? false,
        },
        validate: validatorResolver({
            fixed_total: Validator.number().required().gte(0),
            temp_total: Validator.number().required().gte(0),
        }),
    });

    const handleSubmit = async (values: ConfigFormValues) => {
        try {
            await updateConfigMutation.mutateAsync({
                fixed_total: values.fixed_total,
                temp_total: values.temp_total,
                max_registered_temp: values.max_registered_temp,
                break_minutes: values.break_minutes,
                is_overtime_by_duration: values.is_overtime_by_duration,
            });
            NotificationHelper.showSuccess(t('Successfully updated shift config'));
            onClose();
        } catch (error) {
            NotificationHelper.showError(
                WeeklyHourLimitHelper.getLengthenedShiftMessage(error, t) ?? t('error|Could not update shift config'),
            );
        }
    };

    return (
        <Modal opened={isOpen} onClose={onClose} title={t('Update config')} size="lg">
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <Flex gap="md" wrap="wrap">
                    <NumberInput
                        label={t('Fixed employees needed')}
                        {...form.getInputProps('fixed_total')}
                        style={{ flex: 1, minWidth: 180 }}
                        min={0}
                    />
                    <NumberInput
                        label={t('Temp employees needed')}
                        {...form.getInputProps('temp_total')}
                        style={{ flex: 1, minWidth: 180 }}
                        min={0}
                    />
                </Flex>
                <Flex gap="md" wrap="wrap" mt="md">
                    <NumberInput
                        label={t('Maximum registered temporary')}
                        {...form.getInputProps('max_registered_temp')}
                        style={{ flex: 1, minWidth: 180 }}
                        min={0}
                    />
                    <NumberInput
                        label={t('Break time (minute)')}
                        {...form.getInputProps('break_minutes')}
                        style={{ flex: 1, minWidth: 180 }}
                        min={0}
                    />
                </Flex>
                <Checkbox
                    mt="md"
                    label={t('Count work beyond shift duration as overtime')}
                    description={t(
                        "Lowers the overtime threshold to the shift's standard duration. Time worked outside the shift schedule still requires the employee's individual overtime permission",
                    )}
                    {...form.getInputProps('is_overtime_by_duration', { type: 'checkbox' })}
                />
                <Flex justify="flex-end" mt="xl" gap="sm">
                    <Button variant="default" onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={updateConfigMutation.isPending}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default ConfigModal;
