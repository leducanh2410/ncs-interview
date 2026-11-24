import React, { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { Button, Flex, Modal, NumberInput, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { Validator, validatorResolver } from '@/validator';
import { WorkplacePosition } from '@/types/models/workplace_position';
import FormHelper from '@/helpers/form.helper';
import { useCreateWorkplacePositionMutation, useUpdateWorkplacePositionMutation } from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
    position: WorkplacePosition | null;
    opened: boolean;
    onClose: () => void;
};

const WorkplacePositionCreateUpdate = ({ workplaceId, position, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateWorkplacePositionMutation(workplaceId);
    const updateMutation = useUpdateWorkplacePositionMutation(workplaceId);

    const form = useForm<Record<string, string>>({
        initialValues: { name: '' },
        validate: validatorResolver({
            name: Validator.string().required().label('position'),
        }),
    });

    useEffect(() => {
        form.setValues({
            name: position?.name || '',
            salary_per_hour: position?.salary_per_hour?.toString() || '',
        });
        form.resetDirty();
    }, [position]);

    const onSubmit = async (data: Record<string, string>) => {
        try {
            if (position) {
                await updateMutation.mutateAsync({
                    positionId: position.id.toString(),
                    data,
                });
            } else {
                await createMutation.mutateAsync(data);
            }
            form.reset();
            onClose();
            notifications.show({
                color: 'teal',
                message:
                    position !== null
                        ? t('Successfully updated workplace position')
                        : t('Successfully created workplace position'),
            });
        } catch (error) {
            FormHelper.setFormError(form, error);
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={position ? t('Edit position') : t('Create position')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <TextInput withAsterisk label={t('Position')} {...form.getInputProps('name')} mb={8} />
                <NumberInput label={t('Salary per hour')} {...form.getInputProps('salary_per_hour')} />
                <Flex justify="end" mt={24} gap={8}>
                    <Button color="gray" onClick={onClose}>
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

export default WorkplacePositionCreateUpdate;
