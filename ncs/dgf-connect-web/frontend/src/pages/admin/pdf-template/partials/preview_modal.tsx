import React, { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuDownload } from 'react-icons/lu';
import { Button, Center, Flex, Loader, Modal, Text } from '@mantine/core';
import { usePreviewPdfMutation } from '@/pages/admin/pdf-template/api';

type Props = {
    opened: boolean;
    templateId: string | number;
    templateName: string;
    onClose: () => void;
};

const PreviewModal: React.FC<Props> = ({ opened, templateId, templateName, onClose }) => {
    const { t } = useTranslation();
    const previewMutation = usePreviewPdfMutation(templateId);
    const [blobUrl, setBlobUrl] = useState<string | null>(null);

    const revokeBlobUrl = useCallback(() => {
        if (blobUrl) {
            URL.revokeObjectURL(blobUrl);
            setBlobUrl(null);
        }
    }, [blobUrl]);

    const handleGenerate = async () => {
        revokeBlobUrl();
        try {
            const blob = await previewMutation.mutateAsync();
            setBlobUrl(URL.createObjectURL(blob));
        } catch {
            // Error handled by mutation state
        }
    };

    const handleDownload = () => {
        if (!blobUrl) return;
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = `${templateName}_preview.pdf`;
        document.body.appendChild(link);
        link.click();
        link.remove();
    };

    const handleClose = () => {
        revokeBlobUrl();
        previewMutation.reset();
        onClose();
    };

    // Revoke blob URL on unmount
    useEffect(() => {
        return () => {
            if (blobUrl) {
                URL.revokeObjectURL(blobUrl);
            }
        };
    }, [blobUrl]);

    return (
        <Modal opened={opened} onClose={handleClose} title={t('Preview: {{name}}', { name: templateName })} size="xl">
            {!blobUrl && !previewMutation.isPending && (
                <Center mih={200}>
                    <Flex direction="column" align="center" gap="md">
                        <Text size="sm" c="dimmed">
                            {t('Generate a preview PDF using placeholder values.')}
                        </Text>
                        <Button onClick={handleGenerate}>{t('Generate Preview')}</Button>
                    </Flex>
                </Center>
            )}

            {previewMutation.isPending && (
                <Center mih={200}>
                    <Flex direction="column" align="center" gap="md">
                        <Loader />
                        <Text size="sm" c="dimmed">
                            {t('Generating preview...')}
                        </Text>
                    </Flex>
                </Center>
            )}

            {previewMutation.isError && !blobUrl && (
                <Center mih={200}>
                    <Flex direction="column" align="center" gap="md">
                        <Text size="sm" c="red">
                            {t('Failed to generate preview.')}
                        </Text>
                        <Button onClick={handleGenerate}>{t('Retry')}</Button>
                    </Flex>
                </Center>
            )}

            {blobUrl && (
                <>
                    <iframe
                        src={blobUrl}
                        style={{ width: '100%', height: '70vh', border: '1px solid var(--mantine-color-gray-3)' }}
                        title={t('PDF Preview')}
                    />
                    <Flex justify="flex-end" mt="md" gap="sm">
                        <Button variant="light" leftSection={<LuDownload size={14} />} onClick={handleDownload}>
                            {t('Download')}
                        </Button>
                        <Button onClick={handleGenerate} loading={previewMutation.isPending}>
                            {t('Regenerate')}
                        </Button>
                    </Flex>
                </>
            )}
        </Modal>
    );
};

export default PreviewModal;
