import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuFile, LuX } from 'react-icons/lu';
import { Button, Flex, Group, Modal, Text, TextInput } from '@mantine/core';
import { Dropzone, FileWithPath } from '@mantine/dropzone';
import { useForm } from '@mantine/form';
import NotificationHelper from '@/helpers/notification.helper';
import { useCreatePdfTemplateMutation } from '@/pages/admin/pdf-template/api';

type Props = {
    opened: boolean;
    onClose: () => void;
    onSuccess: (templateId: number) => void;
};

const CreateTemplateModal: React.FC<Props> = ({ opened, onClose, onSuccess }) => {
    const { t } = useTranslation();
    const createMutation = useCreatePdfTemplateMutation();
    const [file, setFile] = useState<FileWithPath | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);

    const form = useForm({
        initialValues: {
            name: '',
        },
        validate: {
            name: (value) => (value.trim().length === 0 ? t('Name is required') : null),
        },
    });

    const handleClose = () => {
        form.reset();
        setFile(null);
        setFileError(null);
        onClose();
    };

    const handleSubmit = async (values: { name: string }) => {
        if (!file) {
            setFileError(t('PDF file is required'));
            return;
        }

        const formData = new FormData();
        formData.append('name', values.name.trim());
        formData.append('file', file);

        try {
            const response = await createMutation.mutateAsync(formData);
            NotificationHelper.showSuccess(t('Successfully created PDF template'));
            handleClose();
            if (response.data) {
                onSuccess(response.data.id);
            }
        } catch {
            NotificationHelper.showError(t('Could not create PDF template'));
        }
    };

    return (
        <Modal opened={opened} onClose={handleClose} title={t('Create PDF Template')} size="lg">
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <TextInput
                    label={t('Template Name')}
                    placeholder={t('Enter template name')}
                    required
                    {...form.getInputProps('name')}
                />

                <Text size="sm" fw={500} mt="md" mb={4}>
                    {t('PDF File')} <span style={{ color: 'var(--mantine-color-red-6)' }}>*</span>
                </Text>
                <Dropzone
                    onDrop={(files) => {
                        setFile(files[0]);
                        setFileError(null);
                    }}
                    onReject={() => setFileError(t('Invalid file. Please upload a PDF file under 5MB.'))}
                    maxSize={5 * 1024 * 1024}
                    accept={{ 'application/pdf': ['.pdf'] }}
                    multiple={false}
                >
                    <Group justify="center" gap="md" mih={100} style={{ pointerEvents: 'none' }}>
                        <Dropzone.Accept>
                            <LuCheck size={40} color="var(--mantine-color-blue-6)" />
                        </Dropzone.Accept>
                        <Dropzone.Reject>
                            <LuX size={40} color="var(--mantine-color-red-6)" />
                        </Dropzone.Reject>
                        <Dropzone.Idle>
                            <LuFile size={40} color="var(--mantine-color-dimmed)" />
                        </Dropzone.Idle>
                        <div>
                            <Text size="md" inline>
                                {t('Drag a PDF file here or click to select')}
                            </Text>
                            <Text size="sm" c="dimmed" inline mt={7}>
                                {t('Max file size: 5MB')}
                            </Text>
                        </div>
                    </Group>
                </Dropzone>
                {file && (
                    <Text size="sm" mt="xs" c="blue">
                        {file.name} ({(file.size / 1024).toFixed(1)} KB)
                    </Text>
                )}
                {fileError && (
                    <Text size="sm" mt="xs" c="red">
                        {fileError}
                    </Text>
                )}

                <Flex justify="flex-end" mt="lg" gap="sm">
                    <Button variant="filled" color="gray" onClick={handleClose}>
                        {t('Cancel')}
                    </Button>
                    <Button type="submit" loading={createMutation.isPending}>
                        {t('Create')}
                    </Button>
                </Flex>
            </form>
        </Modal>
    );
};

export default CreateTemplateModal;
