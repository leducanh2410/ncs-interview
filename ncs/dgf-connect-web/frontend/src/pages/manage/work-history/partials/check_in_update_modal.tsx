import React, { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, NumberInput, TextInput, Textarea } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { CheckIn } from '@/types/models/check_in';
import DateHelper, { DATE_STORE_FORMAT, DATE_STORE_FORMAT_WITH_TIME } from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DTimeInput from '@/components/DTimeInput';
import { useUpdateCheckInMutation } from '@/pages/manage/work-history/api';

type Props = {
    checkIn: CheckIn | null;
    onClose: () => void;
};

const CheckInUpdateModal = ({ checkIn, onClose }: Props) => {
    const { t } = useTranslation();
    const updateCheckIn = useUpdateCheckInMutation();
    // Captures the values the modal was opened with, so the "changed?" check driving each
    // reason field's required-ness always compares against the committed CheckIn, not the
    // form's own initialValues object (which useForm freezes at construction time).
    const originalValuesRef = useRef({
        check_in_date: null as string | null,
        check_in_time: '',
        check_out_date: null as string | null,
        check_out_time: '',
        break_minutes: null as number | null,
    });

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            check_in_date: null,
            check_in_time: '',
            check_out_date: null,
            check_out_time: '',
            break_minutes: null,
            check_in_reason: '',
            check_out_reason: '',
            break_minutes_reason: '',
        },
        validate: validatorResolver({
            check_in_date: Validator.string().isSelect().required(),
            check_in_time: Validator.string().required(),
            break_minutes: Validator.number(),
            check_in_reason: Validator.string().requiredIf(
                (values) =>
                    values.check_in_date !== originalValuesRef.current.check_in_date ||
                    values.check_in_time !== originalValuesRef.current.check_in_time,
            ),
            check_out_reason: Validator.string().requiredIf(
                (values) =>
                    values.check_out_date !== originalValuesRef.current.check_out_date ||
                    values.check_out_time !== originalValuesRef.current.check_out_time,
            ),
            break_minutes_reason: Validator.string().requiredIf(
                (values) => values.break_minutes !== originalValuesRef.current.break_minutes,
            ),
        }),
    });

    useEffect(() => {
        if (!checkIn) {
            return;
        }

        const checkInTime = DateHelper.parse(checkIn.check_in_time, DATE_STORE_FORMAT_WITH_TIME);
        const checkOutTime = checkIn.check_out_time
            ? DateHelper.parse(checkIn.check_out_time, DATE_STORE_FORMAT_WITH_TIME)
            : null;
        const values = {
            check_in_date: checkInTime?.format(DATE_STORE_FORMAT) || null,
            check_in_time: checkInTime?.format('HH:mm:ss') || '',
            check_out_date: checkOutTime?.format(DATE_STORE_FORMAT) || null,
            check_out_time: checkOutTime?.format('HH:mm:ss') || '',
            break_minutes: checkIn.break_minutes,
        };
        originalValuesRef.current = values;
        form.setValues({ ...values, check_in_reason: '', check_out_reason: '', break_minutes_reason: '' });
        form.resetDirty();
    }, [checkIn]);

    const onSubmit = async (values: Record<string, ParamType>) => {
        if (!checkIn) {
            return;
        }
        const data = FormHelper.toParams(values);

        try {
            await updateCheckIn.mutateAsync({ id: checkIn.id.toString(), data });
            NotificationHelper.showSuccess(t('Edit request submitted, pending approval'));
            form.reset();
            onClose();
        } catch (e) {
            FormHelper.setFormError(form, e);
        }
    };

    const { startTime, endTime } = DateHelper.formatWorkHour(
        checkIn?.shift.workplace_shift.start_time || '',
        checkIn?.shift.workplace_shift.end_time || '',
    );

    return (
        <Modal opened={checkIn !== null} onClose={onClose} title={t('Update check in data')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                {checkIn !== null && (
                    <Flex direction="column" gap="16">
                        <TextInput label={t('Employee')} value={checkIn.user.name} disabled />
                        <TextInput label={t('Workplace')} value={checkIn.shift.workplace.name} disabled />
                        <TextInput
                            label={t('Shift')}
                            value={`${DateHelper.parseAndFormat(checkIn.shift.date)} ${startTime} ~ ${endTime}`}
                            disabled
                        />
                        <DatePickerInput
                            label={t('Check in date')}
                            valueFormat={t('FORMAT_DATE')}
                            rightSection={<LuCalendar size={16} />}
                            {...form.getInputProps('check_in_date')}
                        />
                        <DTimeInput withSecond label={t('Check in time')} {...form.getInputProps('check_in_time')} />
                        <Textarea
                            label={t('Reason for change in check-in time')}
                            withAsterisk={
                                form.values.check_in_date !== originalValuesRef.current.check_in_date ||
                                form.values.check_in_time !== originalValuesRef.current.check_in_time
                            }
                            {...form.getInputProps('check_in_reason')}
                        />
                        <DatePickerInput
                            label={t('Check out date')}
                            valueFormat={t('FORMAT_DATE')}
                            rightSection={<LuCalendar size={16} />}
                            {...form.getInputProps('check_out_date')}
                        />
                        <DTimeInput withSecond label={t('Check out time')} {...form.getInputProps('check_out_time')} />
                        <Textarea
                            label={t('Reason for change in check-out time')}
                            withAsterisk={
                                form.values.check_out_date !== originalValuesRef.current.check_out_date ||
                                form.values.check_out_time !== originalValuesRef.current.check_out_time
                            }
                            {...form.getInputProps('check_out_reason')}
                        />
                        <NumberInput
                            label={t('Break time (minute)')}
                            {...form.getInputProps('break_minutes')}
                            min={0}
                        />
                        <Textarea
                            label={t('Reason for change in break time')}
                            withAsterisk={form.values.break_minutes !== originalValuesRef.current.break_minutes}
                            {...form.getInputProps('break_minutes_reason')}
                        />
                    </Flex>
                )}
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
                        loading={updateCheckIn.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default CheckInUpdateModal;
