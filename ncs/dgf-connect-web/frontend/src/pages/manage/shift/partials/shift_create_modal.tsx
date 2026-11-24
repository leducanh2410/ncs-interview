import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, NumberInput, Select, SimpleGrid } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError, ApiValidationError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import { masterAtom } from '@/pages/global.state';
import { useGetWorkplaceShiftsQuery } from '@/pages/admin/workplace/api';
import { useCreateShiftMutation } from '@/pages/manage/shift/api';

type Props = {
    isOpened: boolean;
    onClose: () => void;
    date: string;
};

const ShiftCreateModal: React.FC<Props> = ({ isOpened, onClose, date }) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const createMutation = useCreateShiftMutation();
    const [workplaceId, setWorkplaceId] = React.useState<string | null>(null);
    const getShiftQuery = useGetWorkplaceShiftsQuery(workplaceId || '', true);

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            workplace_shift_id: null,
            fixed_total: 0,
            temp_total: 0,
            max_registered_temp: '',
            break_minutes: '',
        },
        validate: validatorResolver({
            fixed_total: Validator.number().required().gte(0),
            temp_total: Validator.number().required().gte(0),
        }),
    });

    const handleSubmit = async (values: Record<string, ParamType>) => {
        try {
            await createMutation.mutateAsync({ ...values, date });
            form.reset();
            onClose();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                FormHelper.setFormError(form, error);
                return;
            }

            if (error instanceof ApiError) {
                if (error.message === ErrorCodeEnum.SHIFT_ALREADY_EXISTS.value) {
                    form.setFieldError('workplace_shift_id', t('validation|Shift is already created'));
                    return;
                }
                if (error.message === ErrorCodeEnum.SHIFT_CANNOT_CREATE_INACTIVE.value) {
                    form.setFieldError('workplace_shift_id', t('validation|Workplace shift is inactive'));
                    return;
                }
            }
            notifications.show({
                color: 'red',
                message: t('error|Could not create shift'),
            });
        }
    };

    return (
        <Modal opened={isOpened} onClose={onClose} title={t('Create shift')}>
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <SimpleGrid cols={1}>
                    <DatePickerInput
                        label={t('Date')}
                        disabled
                        valueFormat={t('FORMAT_DATE')}
                        rightSection={<LuCalendar size={16} />}
                        value={date}
                    />
                    <Select
                        label={t('Workplace')}
                        data={masterData.workplaces.map((workplace) => {
                            return {
                                label: workplace.name,
                                value: workplace.id.toString(),
                            };
                        })}
                        searchable
                        onChange={setWorkplaceId}
                    />
                    <Select
                        label={t('Shift')}
                        data={getShiftQuery.data?.data?.map((shift) => {
                            const { startTime, endTime } = DateHelper.formatWorkHour(shift.start_time, shift.end_time);
                            return {
                                label: `${startTime} ~ ${endTime}`,
                                value: shift.id.toString(),
                            };
                        })}
                        disabled={getShiftQuery.isPending}
                        {...form.getInputProps('workplace_shift_id')}
                    />
                    <NumberInput
                        withAsterisk
                        label={t('Fixed position')}
                        {...form.getInputProps('fixed_total')}
                        min={0}
                    />
                    <NumberInput
                        withAsterisk
                        label={t('Temporary position')}
                        {...form.getInputProps('temp_total')}
                        min={0}
                    />
                    <NumberInput
                        label={t('Maximum registered temporary')}
                        {...form.getInputProps('max_registered_temp')}
                        min={0}
                    />
                    <NumberInput label={t('Break time (minute)')} {...form.getInputProps('break_minutes')} min={0} />
                </SimpleGrid>
                <Flex justify="flex-end" mt={16} gap={8}>
                    <Button
                        color="gray"
                        onClick={() => {
                            form.reset();
                            onClose();
                        }}
                    >
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" color="teal" leftSection={<LuSave />} loading={createMutation.isPending}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default ShiftCreateModal;
