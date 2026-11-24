import React, { useEffect, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuSave } from 'react-icons/lu';
import { Box, Button, Flex, Modal, Select, Text } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import NotificationHelper from '@/helpers/notification.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import { DUserSelectRemoteMultiple } from '@/components/DUserSelectRemote';
import { masterAtom } from '@/pages/global.state';
import { useAddUsersToShiftMutation, useGetShiftsSimpleQuery } from '@/pages/manage/work-history/api';

type Props = {
    opened: boolean;
    onClose: () => void;
};

const CheckInAddModal = ({ opened, onClose }: Props) => {
    const { t } = useTranslation();
    const [masterData] = useAtom(masterAtom);
    const addUsersToShift = useAddUsersToShiftMutation();

    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'work_history',
    );
    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            date: null,
            shift_id: null,
            workplace_id: null,
            user_ids: [],
        },
        validate: validatorResolver({
            date: Validator.string().isSelect().required(),
            shift_id: Validator.string().isSelect().required().label('shift'),
            workplace_id: Validator.string().isSelect().required().label('workplace'),
            user_ids: Validator.array().required().minLength(1).label('user'),
        }),
    });

    const closeModal = () => {
        form.reset();
        onClose();
    };

    const selectedDate = useMemo(() => {
        if (!form.values.date) {
            return '';
        }
        return form.values.date as string;
    }, [form.values.date]);

    const selectedWorkplace = useMemo(() => {
        if (!form.values.workplace_id) {
            return '';
        }
        return form.values.workplace_id as string;
    }, [form.values.workplace_id]);

    useEffect(() => {
        form.setFieldValue('shift_id', null);
    }, [selectedDate]);

    const getShiftQuery = useGetShiftsSimpleQuery(selectedDate, selectedWorkplace);
    const shiftSelect = useMemo(() => {
        if (!getShiftQuery.data || !getShiftQuery.data.data) {
            return [];
        }
        return getShiftQuery.data.data.map((shift) => {
            const { startTime, endTime } = DateHelper.formatWorkHour(
                shift.workplace_shift.start_time,
                shift.workplace_shift.end_time,
            );

            return {
                value: shift.id.toString(),
                label: `${shift.workplace.name} (${startTime}-${endTime})`,
            };
        });
    }, [getShiftQuery.data]);

    const onSubmit = async (values: Record<string, ParamType>) => {
        try {
            await addUsersToShift.mutateAsync({
                shiftId: values.shift_id as string,
                userIds: values.user_ids as string[],
            });
            NotificationHelper.showSuccess(t('Successfully added user to shift'));
            closeModal();
        } catch (error) {
            if (error instanceof ApiValidationError) {
                for (const key of Object.keys(error.data)) {
                    const errorMsg = error.data[key][0];
                    if (errorMsg.startsWith('not_in_workspace:')) {
                        const [, staffNos] = errorMsg.split(':');
                        form.setFieldError(
                            key,
                            i18n.t(`validation|Users not in workspace: {{staffNos}}`, { staffNos }),
                        );
                        continue;
                    }
                    form.setFieldError(key, i18n.t(`validation|${error.data[key][0]}`));
                }
            }
        }
    };

    return (
        <Modal opened={opened} onClose={closeModal} title={t('Add user to shift')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap="8">
                    <DatePickerInput
                        label={t('Date')}
                        valueFormat={t('FORMAT_DATE')}
                        rightSection={<LuCalendar size={16} />}
                        {...form.getInputProps('date')}
                    />
                    <Select
                        label={t('Workplace')}
                        data={accessibleWorkplaces.map((workplace) => {
                            return {
                                label: workplace.name,
                                value: workplace.id.toString(),
                            };
                        })}
                        searchable
                        {...form.getInputProps('workplace_id')}
                    />
                    <Select
                        label={t('Shift')}
                        data={shiftSelect}
                        disabled={getShiftQuery.isPending}
                        {...form.getInputProps('shift_id')}
                    />
                    <Box>
                        <DUserSelectRemoteMultiple
                            label={t('Select user')}
                            placeholder={t('Select user')}
                            {...form.getInputProps('user_ids')}
                        />
                        {form.errors.user_ids && (
                            <Text mt={8} c="red" size="12px">
                                {form.errors.user_ids}
                            </Text>
                        )}
                    </Box>
                </Flex>
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={closeModal}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        type="submit"
                        variant="filled"
                        color="teal"
                        leftSection={<LuSave />}
                        loading={addUsersToShift.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default CheckInAddModal;
