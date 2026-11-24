import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, NumberInput, Radio, Select } from '@mantine/core';
import { DatePickerInput, MonthPickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { SalaryProcess } from '@/types/models/salary';
import DateHelper, {
    DATE_STORE_FORMAT,
    DATE_STORE_FORMAT_MONTH,
    DATE_STORE_FORMAT_WITH_TIME,
} from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import { masterAtom } from '@/pages/global.state';
import { useCreateSalaryProcessMutation, useUpdateSalaryProcessMutation } from '@/pages/manage/salary/process/api';

type Props = {
    salaryProcess: SalaryProcess | null;
    opened: boolean;
    onClose: () => void;
};

const SalaryProcessCreateUpdateModal = ({ salaryProcess, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            workplace_id: null,
            month: DateHelper.now().format(DATE_STORE_FORMAT),
            priority: 0,
            is_scheduled: '1',
            scheduled_at: null,
        },
        validate: validatorResolver({
            workplace_id: Validator.string().isSelect().label(t('field|workplace')).required(),
            month: Validator.string().isSelect().required(),
            priority: Validator.number().required(),
            scheduled_at: Validator.string()
                .isSelect()
                .label(t('field|scheduled_date'))
                .requiredIf((values) => values.is_scheduled === '1'),
        }),
    });

    useEffect(() => {
        if (salaryProcess) {
            form.setValues({
                workplace_id: salaryProcess.workplace_id.toString(),
                month: `${salaryProcess.month}-01`,
                priority: salaryProcess.priority,
                is_scheduled: salaryProcess.scheduled_at ? '1' : '0',
                scheduled_at: DateHelper.parseAndFormat(
                    salaryProcess.scheduled_at,
                    DATE_STORE_FORMAT_WITH_TIME,
                    DATE_STORE_FORMAT,
                ),
            });
        } else {
            form.setValues({
                workplace_id: null,
                month: DateHelper.now().format(DATE_STORE_FORMAT),
                priority: 0,
                is_scheduled: '1',
                scheduled_at: null,
            });
        }
        form.resetDirty();
    }, [salaryProcess]);

    const createSalaryRequest = useCreateSalaryProcessMutation();
    const updateSalaryRequest = useUpdateSalaryProcessMutation();

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const onSubmit = async (values: Record<string, ParamType>) => {
        const data: Record<string, ParamType> = {
            workplace_id: values.workplace_id as string,
            month: DateHelper.parseAndFormat(values.month as string, DATE_STORE_FORMAT, DATE_STORE_FORMAT_MONTH),
            priority: values.priority as number,
        };
        if (values.is_scheduled === '1') {
            data.scheduled_at = values.scheduled_at;
        }
        try {
            if (salaryProcess) {
                await updateSalaryRequest.mutateAsync({ id: salaryProcess.id, data });
                NotificationHelper.showSuccess(t('Successfully update salary payment schedule'));
            } else {
                await createSalaryRequest.mutateAsync(data);
                NotificationHelper.showSuccess(t('Successfully create salary payment schedule'));
            }
            closeModal();
        } catch (error) {
            let message = salaryProcess
                ? t('error|Could not update salary payment schedule')
                : t('error|Could not create salary payment schedule');
            NotificationHelper.showError(message);
        }
    };

    return (
        <Modal
            opened={opened}
            onClose={closeModal}
            title={salaryProcess ? t('Update salary payment schedule') : t('Create salary payment schedule')}
        >
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap="8">
                    <Select
                        placeholder={t('Select workplace')}
                        data={masterData.workplaces.map((workplace) => ({
                            value: workplace.id.toString(),
                            label: workplace.name,
                        }))}
                        searchable
                        {...form.getInputProps('workplace_id')}
                    />
                    <MonthPickerInput
                        label={t('Month')}
                        valueFormat={t('FORMAT_MONTH')}
                        rightSection={<LuCalendar size={16} />}
                        {...form.getInputProps('month')}
                    />
                    <NumberInput label={t('Priority')} {...form.getInputProps('priority')} min={0} max={100} />
                    <Radio.Group label={t('Is scheduled')} {...form.getInputProps('is_scheduled')}>
                        <Radio value={'0'} label={t('No')} mb={4} />
                        <Radio value={'1'} label={t('Yes')} />
                    </Radio.Group>
                    {form.values.is_scheduled === '1' && (
                        <DatePickerInput
                            label={t('Scheduled date')}
                            valueFormat={t('FORMAT_DATE')}
                            rightSection={<LuCalendar size={16} />}
                            {...form.getInputProps('scheduled_at')}
                        />
                    )}
                </Flex>
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" variant="filled" color="teal" leftSection={<LuSave />}>
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default SalaryProcessCreateUpdateModal;
