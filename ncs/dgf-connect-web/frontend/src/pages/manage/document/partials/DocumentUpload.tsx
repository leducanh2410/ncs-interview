import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuCheck, LuFile, LuFileText, LuImage, LuTrash2, LuX } from 'react-icons/lu';
import { ActionIcon, Box, Flex, Group, List, Text } from '@mantine/core';
import { Dropzone, FileWithPath } from '@mantine/dropzone';

type CustomFileUploaderProps = {
    onFilesChange?: (files: File[]) => void;
    acceptTypes?: string[];
};

const getIconFromType = (type: string) => {
    switch (type) {
        case 'image/jpeg':
        case 'image/png':
            return LuImage;
        default:
            return LuFileText;
    }
};

const DocumentUpload: React.FC<CustomFileUploaderProps> = ({ onFilesChange }) => {
    const { t } = useTranslation();
    const [files, setFiles] = useState<FileWithPath[]>([]);

    const handleFiles = (fileList: FileWithPath[]) => {
        if (!fileList.length) return;
        const updated = [...files, ...fileList];
        setFiles(updated);
        onFilesChange?.(updated);
    };

    const removeFile = (indexToRemove: number) => {
        const updated = files.filter((_, index) => index !== indexToRemove);
        setFiles(updated);
        onFilesChange?.(updated);
    };

    return (
        <div>
            <Dropzone
                onDrop={(files) => handleFiles(files)}
                onReject={(files) => console.log('rejected files', files)}
                maxSize={5 * 1024 ** 2}
                accept={{
                    'image/jpeg': ['.jpg', '.jpeg'],
                    'image/png': ['.png'],
                    'application/pdf': ['.pdf'],
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'],
                    'application/msword': ['.doc'],
                }}
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
                        <Text size="lg" inline>
                            {t('Drag files here or click to select files')}
                        </Text>
                        <Text size="sm" c="dimmed" inline mt={7}>
                            {t('Supported formats: {{formats}}', { formats: 'jpg, jpeg, png, pdf, docx, doc' })}
                        </Text>
                    </div>
                </Group>
            </Dropzone>
            {files.length > 0 && (
                <Box mt="md">
                    <strong>{t('Selected files')}</strong>
                    <List icon={<></>}>
                        {files.map((file, idx) => {
                            const Icon = getIconFromType(file.type);
                            let fileSize = file.size / 1024;
                            let unit = 'KB';
                            if (fileSize > 1024) {
                                fileSize = fileSize / 1024;
                                unit = 'MB';
                            }
                            return (
                                <List.Item key={`${file.name}-${file.size}-${file.lastModified}`}>
                                    <Flex align="center" gap="sm">
                                        <Icon size={16} />
                                        <Text lh="28px">
                                            {file.name} - {fileSize.toFixed(1)} {unit}
                                        </Text>
                                        <ActionIcon variant="subtle" color="red" onClick={() => removeFile(idx)}>
                                            <LuTrash2 size={16} />
                                        </ActionIcon>
                                    </Flex>
                                </List.Item>
                            );
                        })}
                    </List>
                </Box>
            )}
        </div>
    );
};

export default DocumentUpload;
