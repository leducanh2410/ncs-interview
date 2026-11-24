import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPencilLine, LuSave, LuX } from 'react-icons/lu';
import { Anchor, Box, Button, Checkbox, Flex, NumberInput, SimpleGrid, Skeleton, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import FormHelper from '@/helpers/form.helper';
import { usePermissions } from '@/hooks/usePermission';
import {
    WorkplaceResponseData,
    useCreateWorkplaceMutation,
    useUpdateWorkplaceMutation,
} from '@/pages/admin/workplace/api';

const WorkplaceForm = ({ id, workplace, isFetching, onCancel, onDone }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['WORKPLACE_EDIT', 'WORKPLACE_CREATE']);
    const [isReadOnly, setIsReadOnly] = useState(id !== null);
    const { mutateAsync: updateWorkplace, isPending: isUpdating } = useUpdateWorkplaceMutation(id || '');
    const { mutateAsync: createWorkplace, isPending: isCreating } = useCreateWorkplaceMutation();

    const canSubmit = workplace ? perms.WORKPLACE_EDIT : perms.WORKPLACE_CREATE;

    const form = useForm<Record<string, ParamType>>({
        initialValues: {
            name: '',
            address: '',
            coordinates: '',
            salary_per_hour: null,
            min_rest_hours: null,
            overtime_after_hours: null,
            is_test: false,
            is_yamato: false,
            is_self_register_disabled: false,
        },
        validate: validatorResolver({
            name: Validator.string().required(),
            address: Validator.string().required(),
            coordinates: Validator.string().required(),
            min_rest_hours: Validator.number().integer().gte(0).lte(24),
            overtime_after_hours: Validator.number().integer().gte(1).lte(24),
        }),
    });

    useEffect(() => {
        if (!workplace) {
            return;
        }

        form.setValues({
            name: workplace.name,
            address: workplace.address,
            coordinates: `${workplace.latitude}, ${workplace.longitude}`,
            salary_per_hour: workplace.salary_per_hour,
            min_rest_hours: workplace.min_rest_hours,
            overtime_after_hours: workplace.overtime_after_hours,
            is_test: workplace.is_test ?? false,
            is_yamato: workplace.is_yamato ?? false,
            is_self_register_disabled: workplace.is_self_register_disabled ?? false,
        });
        form.resetDirty();
    }, [workplace]);

    return (
        <Box flex={1}>
            <form
                onSubmit={form.onSubmit(async (values) => {
                    try {
                        const data = FormHelper.toParams(values, {});
                        if (id) {
                            await updateWorkplace(data);
                            setIsReadOnly(true);
                            notifications.show({
                                title: t('Success'),
                                message: t('Successfully updated workplace'),
                                color: 'teal',
                            });
                        } else {
                            await createWorkplace(data);
                            notifications.show({
                                title: t('Success'),
                                message: t('Successfully created workplace'),
                                color: 'teal',
                            });
                        }
                        if (onDone) {
                            onDone();
                        }
                    } catch (error) {
                        if (
                            error instanceof ApiError &&
                            error.message === ErrorCodeEnum.WORKPLACE_TEST_MEMBERSHIP_MISMATCH.value
                        ) {
                            notifications.show({
                                title: t('Error'),
                                message: t(
                                    'error|Cannot change test status while members of the opposite type are assigned',
                                ),
                                color: 'red',
                            });
                        } else {
                            FormHelper.setFormError(form, error);
                        }
                    }
                })}
            >
                <Flex justify="space-between" align="center">
                    {isFetching ? (
                        <Skeleton h={18} w={180} />
                    ) : (
                        <Text size="18px" fw={700}>
                            {workplace?.name}
                        </Text>
                    )}
                    {id !== null &&
                        (isReadOnly ? (
                            <Button
                                leftSection={<LuPencilLine />}
                                onClick={() => {
                                    setIsReadOnly(false);
                                }}
                                disabled={!canSubmit}
                            >
                                {t('Edit')}
                            </Button>
                        ) : (
                            <Flex gap={8}>
                                <Button
                                    leftSection={<LuX />}
                                    type="button"
                                    color="gray"
                                    onClick={() => {
                                        form.reset();
                                        setIsReadOnly(true);
                                        if (onCancel) {
                                            onCancel();
                                        }
                                    }}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button
                                    leftSection={<LuSave />}
                                    type="submit"
                                    color="teal"
                                    loading={isUpdating}
                                    disabled={!canSubmit}
                                >
                                    {t('Save changes')}
                                </Button>
                            </Flex>
                        ))}
                </Flex>
                {isFetching && (
                    <SimpleGrid cols={2} mt="md">
                        <Flex gap={2} direction="column">
                            <Skeleton h={18} w="40px" />
                            <Skeleton h={36} w="100%" />
                        </Flex>
                        <Flex gap={2} direction="column">
                            <Skeleton h={18} w="60px" />
                            <Skeleton h={36} w="100%" />
                        </Flex>
                        <Flex gap={2} direction="column">
                            <Skeleton h={18} w="60px" />
                            <Skeleton h={36} w="100%" />
                        </Flex>
                        <Flex gap={2} direction="column">
                            <Skeleton h={18} w="50px" />
                            <Skeleton h={36} w="100%" />
                        </Flex>
                        <Flex gap={2} direction="column">
                            <Skeleton h={18} w="70px" />
                            <Skeleton h={36} w="100%" />
                        </Flex>
                    </SimpleGrid>
                )}
                {!isFetching && (
                    <SimpleGrid cols={2}>
                        <TextInput
                            withAsterisk
                            label={t('Name')}
                            {...form.getInputProps('name')}
                            disabled={isReadOnly}
                        />
                        <TextInput
                            withAsterisk
                            label={t('Address')}
                            {...form.getInputProps('address')}
                            disabled={isReadOnly}
                        />

                        <TextInput
                            withAsterisk
                            label={
                                <>
                                    {t('Coordinate')}
                                    <Anchor
                                        href={`https://www.google.com/maps/search/?api=1&query=${(
                                            form.values.coordinates as string
                                        ).trim()}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        ml={8}
                                    >
                                        {t('Open Google Maps')}
                                    </Anchor>
                                </>
                            }
                            {...form.getInputProps('coordinates')}
                            disabled={isReadOnly}
                        />
                        <NumberInput
                            label={t('Salary per hour')}
                            disabled={isReadOnly}
                            {...form.getInputProps('salary_per_hour')}
                        />
                        <NumberInput
                            label={t('Minimum rest hours between shifts')}
                            description={t('Leave empty to disable validation')}
                            disabled={isReadOnly}
                            min={0}
                            max={24}
                            {...form.getInputProps('min_rest_hours')}
                        />
                        <NumberInput
                            label={t('Overtime after hours')}
                            description={t('Leave empty to use the system default of 8 hours')}
                            disabled={isReadOnly}
                            min={1}
                            max={24}
                            {...form.getInputProps('overtime_after_hours')}
                        />
                        <Checkbox
                            mt={32}
                            label={t('Test workplace')}
                            description={t('Only test employees can join test workplaces')}
                            disabled={isReadOnly}
                            {...form.getInputProps('is_test', { type: 'checkbox' })}
                        />
                        <Checkbox
                            mt={32}
                            label={t('Yamato workplace')}
                            disabled={isReadOnly}
                            {...form.getInputProps('is_yamato', { type: 'checkbox' })}
                        />
                        <Checkbox
                            mt={32}
                            label={t('Do not allow users to register for this workplace themselves')}
                            description={t('Only an admin can add users to this workplace')}
                            disabled={isReadOnly}
                            {...form.getInputProps('is_self_register_disabled', { type: 'checkbox' })}
                        />
                    </SimpleGrid>
                )}
                {id === null && (
                    <Flex justify="flex-end" mt={16} gap={8}>
                        <Button
                            color="gray"
                            onClick={() => {
                                form.reset();
                                if (onCancel) {
                                    onCancel();
                                }
                            }}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button
                            type="submit"
                            color="teal"
                            leftSection={<LuSave />}
                            loading={isCreating}
                            disabled={!canSubmit}
                        >
                            {t('Save')}
                        </Button>
                    </Flex>
                )}
            </form>
        </Box>
    );
};

type Props = {
    id: string | null;
    workplace: WorkplaceResponseData | null | undefined;
    isFetching: boolean;
    onCancel?: () => void;
    onDone?: () => void;
};

export default WorkplaceForm;
