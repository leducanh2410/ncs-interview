import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, TextInput } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { CompanyHoliday } from '@/types/models/company_holiday';
import DateHelper, { DATE_STORE_FORMAT } from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import {
    useCreateCompanyHolidayMutation,
    useUpdateCompanyHolidayMutation,
} from '@/pages/admin/admin-leave/holidays/api';

type Props = {
    holiday: CompanyHoliday | null;
    opened: boolean;
    onClose: () => void;
};

const HolidayCreateUpdateModal = ({ holiday, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateCompanyHolidayMutation();
    const updateMutation = useUpdateCompanyHolidayMutation();

    const form = useForm<{ date: Date | null; name: string }>({
        initialValues: { date: null, name: '' },
        validate: validatorResolver({
            date: Validator.date().required().label('date'),
            name: Validator.string().required().maxLength(255).label('name'),
        }),
    });

    useEffect(() => {
        form.setValues({
            date: holiday ? (DateHelper.parse(holiday.date)?.toDate() ?? null) : null,
            name: holiday?.name || '',
        });
        form.resetDirty();
    }, [holiday, opened]);

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const onSubmit = async (values: { date: Date | null; name: string }) => {
        if (!values.date) return;
        const data = { date: DateHelper.format(values.date, DATE_STORE_FORMAT), name: values.name };
        try {
            if (holiday) {
                await updateMutation.mutateAsync({ id: holiday.id, data });
            } else {
                await createMutation.mutateAsync(data);
            }
            closeModal();
            notifications.show({
                color: 'teal',
                message: holiday ? t('Successfully updated holiday') : t('Successfully created holiday'),
            });
        } catch (error) {
            FormHelper.setFormError(form, error);
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={holiday ? t('Edit holiday') : t('Add holiday')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <DatePickerInput
                    withAsterisk
                    label={t('Date')}
                    valueFormat={t('FORMAT_DATE')}
                    clearable={false}
                    mb={8}
                    {...form.getInputProps('date')}
                />
                <TextInput withAsterisk label={t('Name')} {...form.getInputProps('name')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="filled"
                        color="teal"
                        leftSection={<LuSave />}
                        loading={createMutation.isPending || updateMutation.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default HolidayCreateUpdateModal;
