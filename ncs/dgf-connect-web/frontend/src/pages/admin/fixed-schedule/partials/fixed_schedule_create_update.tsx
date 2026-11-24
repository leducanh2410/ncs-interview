import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Checkbox, Flex, Modal, Select, Skeleton } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { useAtom } from 'jotai';
import { Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import { FixedScheduleWithRelation } from '@/types/models/fixed_schedule';
import { WorkplacePosition } from '@/types/models/workplace_position';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import WorkplaceHelper from '@/helpers/workplace.helper';
import DUserSelectRemote from '@/components/DUserSelectRemote';
import { masterAtom } from '@/pages/global.state';
import { useCreateFixedScheduleMutation, useUpdateFixedScheduleMutation } from '@/pages/admin/fixed-schedule/api';
import { useGetWorkplacePositionsQuery, useGetWorkplaceShiftsQuery } from '@/pages/admin/workplace/api';
import { ValidationBuilder } from '@/validator/abstract_validator';

const WEEK_DAYS = [1, 2, 3, 4, 5, 6, 0];

const schema: Record<string, ValidationBuilder<ParamType>> = {
    workplace_id: Validator.string().required().isSelect().label('workplace'),
    user_id: Validator.string().required().isSelect().label('user'),
};
for (const day of WEEK_DAYS) {
    schema[`day_${day}_workplace_shift_id`] = Validator.string()
        .isSelect()
        .label('shift')
        .requiredIf((values) => values[`day_${day}_enabled`] as boolean);
}

const toPositionOptionsByDay = (
    positions: Pick<WorkplacePosition, 'id' | 'name'>[],
    fixedSchedule: FixedScheduleWithRelation | null,
) => {
    const activeOptions = positions.map((position) => ({ value: position.id.toString(), label: position.name }));
    const activeIds = new Set(activeOptions.map((option) => option.value));
    const optionsByDay: Record<number, { value: string; label: string }[]> = {};
    for (const day of WEEK_DAYS) {
        optionsByDay[day] = activeOptions;
    }
    for (const detail of fixedSchedule?.details ?? []) {
        const position = detail.workplace_position;
        if (position && !activeIds.has(position.id.toString())) {
            optionsByDay[detail.day] = [...activeOptions, { value: position.id.toString(), label: position.name }];
        }
    }
    return optionsByDay;
};

const FixedScheduleCreateUpdate = ({
    fixedSchedule,
    opened,
    onClose,
    hasWorkplaceShiftListPermission,
    hasWorkplacePositionListPermission = false,
}: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateFixedScheduleMutation();
    const updateMutation = useUpdateFixedScheduleMutation();
    const [masterData] = useAtom(masterAtom);
    const accessibleWorkplaces = WorkplaceHelper.filterWorkplacesByAccess(
        masterData.workplaces,
        masterData.workplace_access,
        'fixed_schedule',
    );

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            workplace_id: null,
            user_id: null,
            day_0_enabled: false,
            day_0_workplace_shift_id: null,
            day_0_workplace_position_id: null,
            day_1_enabled: false,
            day_1_workplace_shift_id: null,
            day_1_workplace_position_id: null,
            day_2_enabled: false,
            day_2_workplace_shift_id: null,
            day_2_workplace_position_id: null,
            day_3_enabled: false,
            day_3_workplace_shift_id: null,
            day_3_workplace_position_id: null,
            day_4_enabled: false,
            day_4_workplace_shift_id: null,
            day_4_workplace_position_id: null,
            day_5_enabled: false,
            day_5_workplace_shift_id: null,
            day_5_workplace_position_id: null,
            day_6_enabled: false,
            day_6_workplace_shift_id: null,
            day_6_workplace_position_id: null,
        },
        validate: validatorResolver(schema),
    });

    const getShiftQuery = useGetWorkplaceShiftsQuery(
        form.values.workplace_id ? (form.values.workplace_id as string) : '',
        hasWorkplaceShiftListPermission,
    );
    const getPositionQuery = useGetWorkplacePositionsQuery(
        form.values.workplace_id ? (form.values.workplace_id as string) : '',
        hasWorkplacePositionListPermission,
    );
    const positionOptionsByDay = toPositionOptionsByDay(getPositionQuery.data?.data ?? [], fixedSchedule);

    useEffect(() => {
        const schedule: Record<string, ParamType> = {};
        for (const day of WEEK_DAYS) {
            schedule[`day_${day}_enabled`] = false;
            schedule[`day_${day}_workplace_shift_id`] = null;
            schedule[`day_${day}_workplace_position_id`] = null;
        }
        if (fixedSchedule?.details.length) {
            for (const detail of fixedSchedule.details) {
                schedule[`day_${detail.day}_enabled`] = true;
                schedule[`day_${detail.day}_workplace_shift_id`] = detail.workplace_shift_id.toString();
                schedule[`day_${detail.day}_workplace_position_id`] = detail.workplace_position_id?.toString() ?? null;
            }
        }
        form.setValues({
            workplace_id: fixedSchedule?.workplace_id.toString() || null,
            user_id: fixedSchedule?.user_id.toString() || null,
            ...schedule,
        });
        form.resetDirty();
    }, [fixedSchedule]);

    const onWorkplaceChange = (workplaceId: string | null) => {
        const reset: Record<string, ParamType> = {};
        for (const day of WEEK_DAYS) {
            reset[`day_${day}_workplace_shift_id`] = null;
            reset[`day_${day}_workplace_position_id`] = null;
        }
        form.setValues({ workplace_id: workplaceId, user_id: null, ...reset });
        form.clearFieldError('workplace_id');
    };

    const onSubmit = async (values: Record<string, ParamType>) => {
        const params: Record<string, any> = {
            workplace_id: values.workplace_id,
            user_id: values.user_id,
            schedules: {},
        };
        for (const day of WEEK_DAYS) {
            if (values[`day_${day}_enabled`]) {
                params.schedules[day] = {
                    day,
                    workplace_shift_id: values[`day_${day}_workplace_shift_id`],
                    workplace_position_id: values[`day_${day}_workplace_position_id`] || null,
                };
            }
        }

        try {
            if (fixedSchedule) {
                await updateMutation.mutateAsync({
                    id: fixedSchedule.id.toString(),
                    data: params,
                });
            } else {
                await createMutation.mutateAsync(params);
            }
            onClose();
            form.reset();
            notifications.show({
                color: 'teal',
                message: fixedSchedule ? t('Successfully updated schedule') : t('Successfully created schedule'),
            });
        } catch (error) {
            if (error instanceof ApiValidationError) {
                for (const key of Object.keys(error.data)) {
                    const message = i18n.t(`validation|${error.data[key][0]}`);
                    if (key.match(/^schedules\.\d+\.workplace_position_id$/)) {
                        const day = parseInt(key.split('.')[1]);
                        form.setFieldError(`day_${day}_workplace_position_id`, message);
                    } else if (key.match(/^schedules\.\d+/)) {
                        const day = parseInt(key.split('.')[1]);
                        form.setFieldError(`day_${day}_workplace_shift_id`, message);
                    } else if (key === 'schedules') {
                        form.setFieldError(`day_1_workplace_shift_id`, message);
                    } else {
                        form.setFieldError(key, message);
                    }
                }
            }
        }
    };

    return (
        <>
            <Modal
                opened={opened}
                onClose={onClose}
                title={fixedSchedule ? t('Edit schedule') : t('Create schedule')}
                size="xl"
            >
                <form onSubmit={form.onSubmit(onSubmit)}>
                    <Flex direction="column" gap="16" mb="md">
                        <Select
                            label={t('Workplace')}
                            data={WorkplaceHelper.toSelectOptions(accessibleWorkplaces, t)}
                            searchable
                            disabled={fixedSchedule !== null}
                            {...form.getInputProps('workplace_id')}
                            onChange={onWorkplaceChange}
                        />
                        <DUserSelectRemote
                            label={t('User')}
                            placeholder={t('Search user')}
                            extraParams={
                                form.values.workplace_id
                                    ? { workplace_id: form.values.workplace_id as string }
                                    : undefined
                            }
                            disabled={fixedSchedule !== null || form.values.workplace_id === null}
                            {...form.getInputProps('user_id')}
                        />
                        {form.values.workplace_id !== null &&
                            WEEK_DAYS.map((day) => {
                                return (
                                    <Flex justify="space-between" align="center" gap="sm" key={day}>
                                        <Checkbox
                                            tt="capitalize"
                                            label={DateHelper.getDayOfWeek(day)}
                                            w={110}
                                            {...form.getInputProps(`day_${day}_enabled`, { type: 'checkbox' })}
                                        />
                                        {getShiftQuery.isPending && <Skeleton h={40} w={260} />}
                                        {!getShiftQuery.isPending && (
                                            <Select
                                                w={260}
                                                placeholder={t('Select shift')}
                                                data={getShiftQuery.data?.data?.map((shift) => {
                                                    const { startTime, endTime } = DateHelper.formatWorkHour(
                                                        shift.start_time,
                                                        shift.end_time,
                                                    );
                                                    return {
                                                        label: `${startTime} ~ ${endTime}`,
                                                        value: shift.id.toString(),
                                                    };
                                                })}
                                                {...form.getInputProps(`day_${day}_workplace_shift_id`)}
                                            />
                                        )}
                                        {getPositionQuery.isPending && hasWorkplacePositionListPermission && (
                                            <Skeleton h={40} w={260} />
                                        )}
                                        {!(getPositionQuery.isPending && hasWorkplacePositionListPermission) && (
                                            <Select
                                                w={260}
                                                placeholder={t('Select position')}
                                                clearable
                                                disabled={!hasWorkplacePositionListPermission}
                                                data={positionOptionsByDay[day]}
                                                {...form.getInputProps(`day_${day}_workplace_position_id`)}
                                            />
                                        )}
                                    </Flex>
                                );
                            })}
                    </Flex>
                    <Flex justify="flex-end" gap="xs">
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
                            leftSection={<LuSave />}
                            loading={createMutation.isPending || updateMutation.isPending}
                        >
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </>
    );
};

type Props = {
    fixedSchedule: FixedScheduleWithRelation | null;
    opened: boolean;
    onClose: () => void;
    hasWorkplaceShiftListPermission: boolean;
    hasWorkplacePositionListPermission?: boolean;
};

export default FixedScheduleCreateUpdate;
