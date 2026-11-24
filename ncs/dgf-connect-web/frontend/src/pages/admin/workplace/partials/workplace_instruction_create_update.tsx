import React, { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuSave } from 'react-icons/lu';
import { AspectRatio, Box, Button, FileButton, Flex, Image, Modal, Select, Text, TextInput } from '@mantine/core';
import { useForm } from '@mantine/form';
import { notifications } from '@mantine/notifications';
import { ParsedUrlQuery } from 'querystring';
import { Validator, validatorResolver } from '@/validator';
import { ParamType } from '@/types/form';
import { WorkplaceInstruction } from '@/types/models/workplace_instruction';
import { InstructionTypeEnum } from '@/enums/instruction_type.enum';
import EnumHelper from '@/helpers/enum.helper';
import FormHelper from '@/helpers/form.helper';
import UrlHelper from '@/helpers/url.helper';
import {
    useCreateWorkplaceInstructionMutation,
    useUpdateWorkplaceInstructionMutation,
} from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
    instruction: WorkplaceInstruction | null;
    opened: boolean;
    onClose: () => void;
};

const WorkplaceInstructionCreateUpdate = ({ workplaceId, instruction, opened, onClose }: Props) => {
    const { t } = useTranslation();
    const createMutation = useCreateWorkplaceInstructionMutation(workplaceId);
    const updateMutation = useUpdateWorkplaceInstructionMutation(workplaceId);
    const [file, setFile] = useState<File | null>(null);

    const form = useForm<Record<string, ParamType>>({
        initialValues: { type: null, title: '', content: '', file: null },
        validate: validatorResolver({
            type: Validator.string().required().isSelect(),
            title: Validator.string().required(),
            content: Validator.string()
                .requiredIf((values) => values.type !== InstructionTypeEnum.IMAGE.value)
                .label('URL'),
            file: Validator.file().requiredIf((values) => values.type === InstructionTypeEnum.IMAGE.value),
        }),
    });

    useEffect(() => {
        form.setValues({
            type: instruction?.type || null,
            title: instruction?.title || '',
            content: instruction?.content || '',
            file: instruction?.type === InstructionTypeEnum.IMAGE.value ? instruction?.content : null,
        });
        setFile(null);
        form.resetDirty();
    }, [instruction]);

    const isImage = form.values.type === InstructionTypeEnum.IMAGE.value;

    const onSubmit = async (values: Record<string, ParamType>) => {
        try {
            let data: ParsedUrlQuery | FormData = FormHelper.toParams(values);
            if (isImage && file) {
                data = new FormData();
                data.append('type', values.type as string);
                data.append('title', values.title as string);
                data.append('file', file);
            } else {
                delete data.file;
            }
            if (instruction) {
                await updateMutation.mutateAsync({
                    instructionId: instruction.id.toString(),
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
                    instruction !== null
                        ? t('Successfully updated workplace instruction')
                        : t('Successfully created workplace instruction'),
            });
        } catch (error) {
            FormHelper.setFormError(form, error);
        }
    };

    return (
        <Modal opened={opened} onClose={onClose} title={instruction ? t('Edit instruction') : t('Create instruction')}>
            <form onSubmit={form.onSubmit(onSubmit)}>
                <Flex direction="column" gap={16}>
                    <Select
                        withAsterisk
                        label={t('Type')}
                        data={EnumHelper.toSelectArray(InstructionTypeEnum)}
                        {...form.getInputProps('type')}
                    />
                    <TextInput withAsterisk label={t('Title')} {...form.getInputProps('title')} />
                    {!isImage && <TextInput withAsterisk label={t('URL')} {...form.getInputProps('content')} />}
                    {isImage && (
                        <Box>
                            <AspectRatio ratio={1.5} w="100%">
                                <Image
                                    src={
                                        file
                                            ? URL.createObjectURL(file)
                                            : UrlHelper.getStaticUrl(form.values.content as string)
                                    }
                                    fallbackSrc="https://placehold.co/600x400?text=No%20Image"
                                    alt={form.values.title as string}
                                    fit="cover"
                                />
                            </AspectRatio>
                            <FileButton
                                accept="image/*"
                                onChange={(file) => {
                                    setFile(file);
                                    form.setFieldValue('file', file?.name || null);
                                }}
                            >
                                {(props) => (
                                    <Button mt={8} variant="outline" {...props}>
                                        {t('Change image')}
                                    </Button>
                                )}
                            </FileButton>
                            {form.errors.file && (
                                <Text mt={8} c="red" size="12px">
                                    {form.errors.file}
                                </Text>
                            )}
                        </Box>
                    )}
                </Flex>

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

export default WorkplaceInstructionCreateUpdate;
