import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Box, Button, Flex, Modal, NumberInput, Select, Title } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { WorkplaceShiftWithRelation } from '@/types/models/workplace_shift';
import DateHelper from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import WeeklyHourLimitHelper from '@/helpers/weekly_hour_limit.helper';
import DTimeInput from '@/components/DTimeInput';
import DUserSelectRemote from '@/components/DUserSelectRemote';
import { useCreateWorkplaceShiftMutation, useUpdateWorkplaceShiftMutation } from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
    shift: WorkplaceShiftWithRelation | null;
    opened: boolean;
    onClose: () => void;
};

type LeaderFormValue = {
    _key: string;
    user_id: string | null;
    bonus: number;
};

const createLeaderFormKey = () => globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2);

const WorkplaceShiftCreateUpdate = ({ workplaceId, shift, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateWorkplaceShiftMutation(workplaceId);
    const updateMutation = useUpdateWorkplaceShiftMutation(workplaceId);

    const initialLeaders: LeaderFormValue[] = [];

    const form = useForm({
        initialValues: {
            start_time: '',
            end_time: '',
            start_break_time: '',
            end_break_time: '',
            salary_per_hour: '',
            check_in_round_type: '1',
            check_in_round_minutes: 0,
            check_out_round_type: '-1',
            check_out_round_minutes: 0,
            date_adjustment: 0,
            leaders: initialLeaders,
        },
        validate: {
            ...validatorResolver({
                start_time: Validator.string().required(),
                end_time: Validator.string().required(),
            }),
            leaders: validatorResolver({
                user_id: Validator.string().isSelect().required().label(t('field|employee')),
                bonus: Validator.number().required().label(t('bonus')),
            }),
        },
    });

    useEffect(() => {
        if (shift) {
            const { startTime, endTime, startBreakTime, endBreakTime } = DateHelper.formatWorkHour(
                shift.start_time,
                shift.end_time,
                shift.start_break_time,
                shift.end_break_time,
            );
            form.setValues({
                start_time: startTime,
                end_time: endTime,
                start_break_time: startBreakTime || '',
                end_break_time: endBreakTime || '',
                salary_per_hour: shift.salary_per_hour?.toString() || '',
                check_in_round_type: shift.check_in_round_type.toString(),
                check_in_round_minutes: shift.check_in_round_minutes,
                check_out_round_type: shift.check_out_round_type.toString(),
                check_out_round_minutes: shift.check_out_round_minutes,
                date_adjustment: shift.date_adjustment,
                leaders: shift.leaders.map((leader) => ({
                    _key: `leader-${leader.user_id}`,
                    user_id: leader.user_id.toString(),
                    bonus: leader.bonus_per_hour,
                })),
            });
        } else {
            form.setValues({
                start_time: '',
                end_time: '',
                start_break_time: '',
                end_break_time: '',
                salary_per_hour: '',
                check_in_round_type: '1',
                check_in_round_minutes: 0,
                check_out_round_type: '-1',
                check_out_round_minutes: 0,
                date_adjustment: 0,
                leaders: initialLeaders,
            });
        }
    }, [shift]);

    const onSubmit = async (data: typeof form.values) => {
        try {
            const submitData = {
                ...data,
                leaders: data.leaders.map((leader) => ({
                    user_id: leader.user_id?.split('|')[0] || '',
                    bonus: leader.bonus,
                })),
            };
            if (shift) {
                await updateMutation.mutateAsync({
                    shiftId: shift.id.toString(),
                    data: submitData,
                });
            } else {
                await createMutation.mutateAsync(submitData);
            }
            onClose();
            form.reset();
            notifications.show({
                color: 'teal',
                message:
                    shift !== null
                        ? t('Successfully updated workplace shift')
                        : t('Successfully created workplace shift'),
            });
        } catch (error) {
            const weeklyHourMessage = WeeklyHourLimitHelper.getLengthenedShiftMessage(error, t);
            if (weeklyHourMessage) {
                notifications.show({ color: 'red', message: weeklyHourMessage, autoClose: false });
                return;
            }
            FormHelper.setFormError(form, error);
        }
    };

    return (
        <Modal
            size="xl"
            opened={opened}
            onClose={() => {
                form.reset();
                onClose();
            }}
            title={shift ? t('Edit shift') : t('Create shift')}
        >
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex gap={16}>
                    <Flex flex={1} direction="column" gap="16">
                        <DTimeInput withAsterisk label={t('Start time')} {...form.getInputProps('start_time')} />
                        <DTimeInput withAsterisk label={t('End time')} {...form.getInputProps('end_time')} />
                        <DTimeInput label={t('Start break time')} {...form.getInputProps('start_break_time')} />
                        <DTimeInput label={t('End break time')} {...form.getInputProps('end_break_time')} />
                        <NumberInput label={t('Salary per hour')} {...form.getInputProps('salary_per_hour')} />
                    </Flex>
                    <Flex flex={1} direction="column" gap="16">
                        <Select
                            label={t('Check in round type')}
                            data={[
                                { label: t('Round up'), value: '1' },
                                { label: t('Round down'), value: '-1' },
                            ]}
                            {...form.getInputProps('check_in_round_type')}
                        />
                        <NumberInput
                            label={t('Check in round minute')}
                            {...form.getInputProps('check_in_round_minutes')}
                        />
                        <Select
                            label={t('Check out round type')}
                            data={[
                                { label: t('Round up'), value: '1' },
                                { label: t('Round down'), value: '-1' },
                            ]}
                            {...form.getInputProps('check_out_round_type')}
                        />
                        <NumberInput
                            label={t('Check out round minute')}
                            {...form.getInputProps('check_out_round_minutes')}
                        />
                        <NumberInput label={t('Day adjustment')} {...form.getInputProps('date_adjustment')} />
                    </Flex>
                </Flex>
                <Flex direction="column" gap={8} mt={16}>
                    <Title order={4}>{t('Leaders')}</Title>
                    {form.getValues().leaders.map((leader, index) => {
                        return (
                            <Flex gap={8} key={leader._key}>
                                <DUserSelectRemote
                                    w={320}
                                    label={t('User')}
                                    placeholder={t('Search user')}
                                    extraParams={{ workplace_id: workplaceId }}
                                    {...form.getInputProps(`leaders.${index}.user_id`)}
                                />
                                <NumberInput
                                    label={t('Bonus')}
                                    min={0}
                                    {...form.getInputProps(`leaders.${index}.bonus`)}
                                />
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    mt={30}
                                    onClick={() => form.removeListItem('leaders', index)}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            </Flex>
                        );
                    })}
                    <Box>
                        <Button
                            onClick={() =>
                                form.insertListItem('leaders', { _key: createLeaderFormKey(), user_id: null, bonus: 0 })
                            }
                        >
                            {t('Add leader')}
                        </Button>
                    </Box>
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
                        loading={createMutation.isPending || updateMutation.isPending}
                    >
                        {t('Save')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default WorkplaceShiftCreateUpdate;
