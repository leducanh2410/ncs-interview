import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuFile, LuX } from 'react-icons/lu';
import { Alert, Button, Flex, Group, Modal, Text } from '@mantine/core';
import { Dropzone, FileWithPath } from '@mantine/dropzone';
import NotificationHelper from '@/helpers/notification.helper';
import { useReUploadPdfMutation } from '@/pages/admin/pdf-template/api';

type Props = {
    opened: boolean;
    templateId: number | null;
    templateName: string;
    onClose: () => void;
};

const ReUploadModal: React.FC<Props> = ({ opened, templateId, templateName, onClose }) => {
    const { t } = useTranslation();
    const reUploadMutation = useReUploadPdfMutation(templateId ?? 0);
    const [file, setFile] = useState<FileWithPath | null>(null);
    const [fileError, setFileError] = useState<string | null>(null);

    const handleClose = () => {
        setFile(null);
        setFileError(null);
        onClose();
    };

    const handleSubmit = async () => {
        if (!file || !templateId) {
            setFileError(t('PDF file is required'));
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        try {
            await reUploadMutation.mutateAsync(formData);
            NotificationHelper.showSuccess(t('Successfully re-uploaded PDF'));
            handleClose();
        } catch {
            NotificationHelper.showError(t('Could not re-upload PDF'));
        }
    };

    return (
        <Modal
            opened={opened}
            onClose={handleClose}
            title={t('Re-upload PDF: {{name}}', { name: templateName })}
            size="lg"
        >
            <Alert color="yellow" mb="md">
                {t('Existing field definitions will be preserved but may no longer align with the new PDF.')}
            </Alert>

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
                <Button onClick={handleSubmit} loading={reUploadMutation.isPending} disabled={!file}>
                    {t('Re-upload')}
                </Button>
            </Flex>
        </Modal>
    );
};

export default ReUploadModal;
