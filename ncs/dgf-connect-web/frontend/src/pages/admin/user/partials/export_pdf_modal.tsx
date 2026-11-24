import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText } from 'react-icons/lu';
import Link from 'next/link';
import { Button, Flex, Group, Modal, Select, Text } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import type { UserExportFilters } from '@/types/user_export';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import { usePermissions } from '@/hooks/usePermission';
import { useCreateBatchExportMutation, useGetPdfTemplatesQuery } from '@/pages/admin/pdf-template/api';

type Props = {
    isOpened: boolean;
    onClose: () => void;
    selectedUserIds: number[];
    selectAll: boolean;
    totalUsers: number;
    filters: UserExportFilters;
};

const ExportPdfModal = ({ isOpened, onClose, selectedUserIds, selectAll, totalUsers, filters }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'PDF_TEMPLATE_EXPORT']);
    const [selectedTemplateId, setSelectedTemplateId] = useState<string | null>(null);

    const { data: templatesData, isPending: isTemplatesPending } = useGetPdfTemplatesQuery({}, perms.PDF_TEMPLATE_LIST);

    const createBatchMutation = useCreateBatchExportMutation();

    const unlockedTemplates: { value: string; label: string }[] = [];
    for (const template of templatesData?.data?.data ?? []) {
        if (template.lock_info) continue;
        unlockedTemplates.push({
            value: template.id.toString(),
            label: template.name,
        });
    }

    const userCount = selectAll ? totalUsers : selectedUserIds.length;

    const handleSubmit = async () => {
        if (!selectedTemplateId) {
            NotificationHelper.showError(t('Please select a template'));
            return;
        }

        try {
            const payload = selectAll
                ? {
                      template_id: parseInt(selectedTemplateId, 10),
                      select_all: true,
                      filters,
                  }
                : {
                      template_id: parseInt(selectedTemplateId, 10),
                      user_ids: selectedUserIds,
                      select_all: false,
                  };

            const response = await createBatchMutation.mutateAsync(payload);

            if (response.data) {
                const batchId = response.data.id;
                onClose();
                setSelectedTemplateId(null);
                notifications.show({
                    message: (
                        <Flex gap="xs" align="center">
                            <Text>{t('Export started successfully')}</Text>
                            <Link
                                href={`${RouteHelper.getPath('EXPORT_BATCH_DETAIL', { id: batchId })}?type=pdf`}
                                style={{ textDecoration: 'underline' }}
                            >
                                {t('View progress')}
                            </Link>
                        </Flex>
                    ),
                    color: 'teal',
                });
            }
        } catch (error) {
            if (error instanceof ApiError) {
                if (error.status === 423) {
                    NotificationHelper.showError(t('Template is currently locked. Please try again later.'));
                } else {
                    NotificationHelper.showError(error.message || t('Failed to start export'));
                }
            } else {
                NotificationHelper.showError(t('Failed to start export'));
            }
        }
    };

    const handleClose = () => {
        setSelectedTemplateId(null);
        onClose();
    };

    return (
        <Modal opened={isOpened} onClose={handleClose} title={t('Export PDF')} size="md">
            <Flex direction="column" gap="md">
                <Text>
                    {selectAll
                        ? t('Exporting for all {{count}} users matching current filters', { count: userCount })
                        : t('Exporting for {{count}} selected users', { count: userCount })}
                </Text>

                <Select
                    label={t('Template')}
                    placeholder={t('Select a template')}
                    data={unlockedTemplates}
                    value={selectedTemplateId}
                    onChange={setSelectedTemplateId}
                    searchable
                    clearable
                    disabled={isTemplatesPending || createBatchMutation.isPending}
                />

                {unlockedTemplates.length === 0 && !isTemplatesPending && (
                    <Text c="dimmed" size="sm">
                        {t('No unlocked templates available')}
                    </Text>
                )}

                <Group justify="flex-end" mt="md">
                    <Button variant="default" onClick={handleClose} disabled={createBatchMutation.isPending}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        leftSection={<LuFileText />}
                        onClick={handleSubmit}
                        loading={createBatchMutation.isPending}
                        disabled={!selectedTemplateId || userCount === 0}
                    >
                        {t('Export')}
                    </Button>
                </Group>
            </Flex>
        </Modal>
    );
};

export default ExportPdfModal;
