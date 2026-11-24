import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuTrash2 } from 'react-icons/lu';
import { Button, Flex, Paper } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { ErrorCodeEnum } from '@/enums/error_code.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import DConfirmModal from '@/components/DConfirmModal';
import WorkplaceForm from '@/pages/admin/workplace/partials/workplace_form';
import WorkplaceImages from '@/pages/admin/workplace/partials/workplace_images';
import { usePermissions } from '@/hooks/usePermission';
import { WorkplaceResponseData, useDeleteWorkplaceMutation } from '@/pages/admin/workplace/api';

const WorkplaceInfo = ({ id, workplace, isFetching }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['WORKPLACE_DELETE']);
    const deleteMutation = useDeleteWorkplaceMutation();
    const [isShowDelete, { open: openDelete, close: closeDelete }] = useDisclosure(false);

    return (
        <Paper mt="md" p="md">
            <Flex justify="space-between" gap="24">
                <Flex direction="column" gap={16}>
                    <WorkplaceImages workplaceId={id} />
                    <Button
                        variant="filled"
                        color="red"
                        fullWidth
                        leftSection={<LuTrash2 size={12} />}
                        onClick={openDelete}
                        loading={deleteMutation.isPending}
                        disabled={!perms.WORKPLACE_DELETE}
                    >
                        {t('Delete workplace')}
                    </Button>
                </Flex>
                <WorkplaceForm id={id} workplace={workplace} isFetching={isFetching} />
            </Flex>
            <DConfirmModal
                opened={isShowDelete}
                title={t('Delete workplace')}
                message={t('Are you sure you want to delete this workplace? This action cannot be undone.')}
                onConfirm={async () => {
                    try {
                        await deleteMutation.mutateAsync(id);
                        NotificationHelper.showSuccess(t('Successfully deleted workplace'));
                    } catch (e) {
                        let message = t('error|Could not delete workplace');
                        if (e instanceof ApiError) {
                            if (e.message === ErrorCodeEnum.WORKPLACE_CANNOT_DELETE_WITH_SHIFT.value) {
                                message = t('error|Could not delete workplace: Workplace has shifts');
                            }
                        }
                        NotificationHelper.showError(message);
                    } finally {
                        closeDelete();
                    }
                }}
                onCancel={closeDelete}
                confirmButtonLabel={t('Delete workplace')}
                confirmButtonColor="red"
            />
        </Paper>
    );
};

type Props = {
    id: string;
    workplace: WorkplaceResponseData | null | undefined;
    isFetching: boolean;
};

export default WorkplaceInfo;
