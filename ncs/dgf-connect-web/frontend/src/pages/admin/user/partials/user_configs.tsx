import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCalendar, LuPencilLine, LuSave } from 'react-icons/lu';
import { Button, Flex, Grid, Loader, Modal, NumberInput, Title } from '@mantine/core';
import { DatePickerInput } from '@mantine/dates';
import { useForm } from '@mantine/form';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import DateHelper from '@/helpers/date.helper';
import FormHelper from '@/helpers/form.helper';
import DDataTable from '@/components/DDataTable';
import { usePermissions } from '@/hooks/usePermission';
import { useGetUserConfigsQuery, useUpdateUserConfigsMutation } from '@/pages/admin/user/api';

const UserConfigs = ({ id }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_CONFIG_LIST', 'USER_CONFIG_EDIT']);
    const { data, isPending } = useGetUserConfigsQuery(id, perms.USER_CONFIG_LIST);
    const { mutateAsync: updateUserConfig } = useUpdateUserConfigsMutation(id);
    const [isShowUpdate, { open: openUpdate, close: closeUpdate }] = useDisclosure(false);

    const configs = data?.data;
    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            max_shift_per_day: null,
            max_hour_per_week: null,
            max_shift_per_day_start_date: null,
            max_shift_per_day_expiry_date: null,
            max_hour_per_week_start_date: null,
            max_hour_per_week_expiry_date: null,
        },
        validate: validatorResolver({
            max_shift_per_day: Validator.number().required(),
            max_hour_per_week: Validator.number().required(),
            max_shift_per_day_start_date: Validator.string().isSelect(),
            max_shift_per_day_expiry_date: Validator.string().isSelect(),
            max_hour_per_week_start_date: Validator.string().isSelect(),
            max_hour_per_week_expiry_date: Validator.string().isSelect(),
        }),
    });

    useEffect(() => {
        if (!configs) {
            return;
        }
        form.setValues({
            max_shift_per_day: parseInt(configs.max_shift_per_day),
            max_hour_per_week: parseInt(configs.max_hour_per_week),
            max_shift_per_day_start_date: configs.max_shift_per_day_start_date,
            max_shift_per_day_expiry_date: configs.max_shift_per_day_expiry_date,
            max_hour_per_week_start_date: configs.max_hour_per_week_start_date,
            max_hour_per_week_expiry_date: configs.max_hour_per_week_expiry_date,
        });
        form.resetDirty();
    }, [configs]);

    if (isPending) {
        return (
            <Flex w="100%" h="200" align="center" justify="center">
                <Loader />
            </Flex>
        );
    }

    return (
        <>
            <Flex justify="space-between" align="center" mb={16}>
                <Title order={3} size={16}>
                    {t('Configuration')}
                </Title>
                <Button h={32} leftSection={<LuPencilLine />} onClick={openUpdate} disabled={!perms.USER_CONFIG_EDIT}>
                    {t('Edit')}
                </Button>
            </Flex>
            <DDataTable
                columns={[
                    {
                        name: t('Configuration name'),
                        key: 'name',
                    },
                    {
                        name: t('Value'),
                        key: 'value',
                    },
                    {
                        name: t('Start date'),
                        key: 'start_date',
                        render: (data) => DateHelper.parseAndFormat(data.start_date),
                    },
                    {
                        name: t('Expiry date'),
                        key: 'expiry_date',
                        render: (data) => DateHelper.parseAndFormat(data.expiry_date),
                    },
                ]}
                data={[
                    {
                        name: t('Max shifts per day'),
                        value: configs?.max_shift_per_day,
                        start_date: configs?.max_shift_per_day_start_date,
                        expiry_date: configs?.max_shift_per_day_expiry_date,
                    },
                    {
                        name: t('Max hours per week'),
                        value: configs?.max_hour_per_week,
                        start_date: configs?.max_hour_per_week_start_date,
                        expiry_date: configs?.max_hour_per_week_expiry_date,
                    },
                ]}
                isFetching={isPending}
            />
            <Modal size="xl" opened={isShowUpdate} onClose={closeUpdate} title={t('Edit configuration')}>
                <form
                    onSubmit={form.onSubmit(async (values) => {
                        try {
                            const data = FormHelper.toParams(values);
                            await updateUserConfig(data);
                            notifications.show({
                                title: t('Success'),
                                message: t('Successfully updated configuration'),
                                color: 'teal',
                            });
                            closeUpdate();
                        } catch (error) {
                            FormHelper.setFormError(form, error);
                        }
                    })}
                >
                    <Grid>
                        <Grid.Col span={4}>
                            <NumberInput
                                withAsterisk
                                label={t('Max shifts per day')}
                                {...form.getInputProps('max_shift_per_day')}
                            />
                        </Grid.Col>
                        <Grid.Col span={4}>
                            <DatePickerInput
                                label={t('Start date')}
                                valueFormat={t('FORMAT_DATE')}
                                clearable
                                leftSection={<LuCalendar size={14} />}
                                {...form.getInputProps('max_shift_per_day_start_date')}
                            />
                        </Grid.Col>
                        <Grid.Col span={4}>
                            <DatePickerInput
                                label={t('Expiry date')}
                                valueFormat={t('FORMAT_DATE')}
                                clearable
                                leftSection={<LuCalendar size={14} />}
                                {...form.getInputProps('max_shift_per_day_expiry_date')}
                            />
                        </Grid.Col>
                    </Grid>

                    <Grid>
                        <Grid.Col span={4}>
                            <NumberInput
                                withAsterisk
                                label={t('Max hours per week')}
                                {...form.getInputProps('max_hour_per_week')}
                            />
                        </Grid.Col>
                        <Grid.Col span={4}>
                            <DatePickerInput
                                label={t('Start date')}
                                valueFormat={t('FORMAT_DATE')}
                                clearable
                                leftSection={<LuCalendar />}
                                {...form.getInputProps('max_hour_per_week_start_date')}
                            />
                        </Grid.Col>
                        <Grid.Col span={4}>
                            <DatePickerInput
                                label={t('Expiry date')}
                                valueFormat={t('FORMAT_DATE')}
                                clearable
                                leftSection={<LuCalendar />}
                                {...form.getInputProps('max_hour_per_week_expiry_date')}
                            />
                        </Grid.Col>
                    </Grid>
                    <Flex justify="flex-end" mt={16} gap={8}>
                        <Button
                            color="gray"
                            onClick={() => {
                                form.reset();
                                closeUpdate();
                            }}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" color="teal" leftSection={<LuSave />}>
                            {t('Save')}
                        </Button>
                    </Flex>
                </form>
            </Modal>
        </>
    );
};

type Props = {
    id: string;
};
export default UserConfigs;
