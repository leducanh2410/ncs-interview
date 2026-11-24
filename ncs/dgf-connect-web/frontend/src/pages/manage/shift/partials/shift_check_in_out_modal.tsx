import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSave } from 'react-icons/lu';
import { Button, Flex, Modal } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { ApiValidationError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DTimeInput from '@/components/DTimeInput';
import getShiftActionErrorMessage from '@/pages/manage/shift/partials/shift_action_error';
import { useCheckInMutation, useCheckOutMutation } from '@/pages/manage/shift/api';

type Props = {
    shiftId: string;
    date: string;
    shiftUserId: string | null;
    setShiftUserId: (shiftUserId: string | null) => void;
    isCheckIn: boolean;
    onSuccess?: (values: { date: string; time: string }, isCheckIn: boolean) => void;
};

type CheckInForm = { date: string; time: string };

const ShiftCheckInOutModal = ({ shiftId, date, shiftUserId, setShiftUserId, isCheckIn, onSuccess }: Props) => {
    const { t } = useTranslation();
    const checkIn = useCheckInMutation(shiftId);
    const checkOut = useCheckOutMutation(shiftId);

    const form = useForm<CheckInForm>({
        initialValues: {
            date,
            time: '',
        },
    });

    const onClose = () => {
        form.reset();
        setShiftUserId(null);
    };

    const onSubmit = async (values: CheckInForm) => {
        if (!shiftUserId) return;

        try {
            const data = {
                shiftUserId,
                date: values.date,
                time: values.time,
            };

            if (isCheckIn) {
                await checkIn.mutateAsync(data);
            } else {
                await checkOut.mutateAsync(data);
            }

            onSuccess?.(values, isCheckIn);
            NotificationHelper.showSuccess(isCheckIn ? t('Check in success') : t('Check out success'));
            onClose();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                FormHelper.setFormError(form, error);
                return;
            }
            NotificationHelper.showError(
                getShiftActionErrorMessage(
                    t,
                    error,
                    isCheckIn ? t('error|Could not check in') : t('error|Could not check out'),
                ),
            );
        }
    };

    return (
        <Modal opened={shiftUserId !== null} onClose={onClose} title={isCheckIn ? t('Check in') : t('Check out')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap="16">
                    <DatePickerInput
                        label={t('Date')}
                        valueFormat={t('FORMAT_DATE')}
                        rightSection={<LuCalendar size={16} />}
                        {...form.getInputProps('date')}
                    />
                    <DTimeInput
                        label={isCheckIn ? t('Check in time') : t('Check out time')}
                        {...form.getInputProps('time')}
                    />
                </Flex>
                <Flex justify="end" mt={24} gap={8}>
                    <Button
                        color="gray"
                        onClick={() => {
                            form.reset();
                            onClose();
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="filled"
                        color="teal"
                        leftSection={<LuSave />}
                        loading={checkIn.isPending || checkOut.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default ShiftCheckInOutModal;
