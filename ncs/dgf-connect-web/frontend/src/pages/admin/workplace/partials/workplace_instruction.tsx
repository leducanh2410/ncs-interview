import React, { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuExternalLink, LuPencilLine, LuPlus, LuTrash2 } from 'react-icons/lu';
import { ActionIcon, Anchor, Button, Flex, Paper, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import { WorkplaceInstruction } from '@/types/models/workplace_instruction';
import { InstructionTypeEnum } from '@/enums/instruction_type.enum';
import EnumHelper from '@/helpers/enum.helper';
import UrlHelper from '@/helpers/url.helper';
import DConfirmModal from '@/components/DConfirmModal';
import DDataTable from '@/components/DDataTable';
import WorkplaceInstructionCreateUpdate from '@/pages/admin/workplace/partials/workplace_instruction_create_update';
import { usePermissions } from '@/hooks/usePermission';
import { useDeleteWorkplaceInstructionMutation, useGetWorkplaceInstructionsQuery } from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
};
const WorkplaceInstructionList = ({ workplaceId }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'WORKPLACE_INSTRUCTION_LIST',
        'WORKPLACE_INSTRUCTION_CREATE',
        'WORKPLACE_INSTRUCTION_EDIT',
        'WORKPLACE_INSTRUCTION_DELETE',
    ]);
    const { data, isLoading } = useGetWorkplaceInstructionsQuery(workplaceId, perms.WORKPLACE_INSTRUCTION_LIST);
    const deleteMutation = useDeleteWorkplaceInstructionMutation(workplaceId);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [currentInstruction, setCurrentInstruction] = useState<WorkplaceInstruction | null>(null);
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);
    return (
        <Paper mt="md" p="md">
            <Flex justify="space-between" align="center" mb="16">
                <Text size="md" fw={700}>
                    {t('Workplace Instruction')}
                </Text>
                <Button
                    size="sm"
                    h={32}
                    leftSection={<LuPlus />}
                    disabled={!perms.WORKPLACE_INSTRUCTION_CREATE}
                    onClick={() => {
                        setCurrentInstruction(null);
                        createUpdateModalAction.open();
                    }}
                >
                    {t('Add')}
                </Button>
            </Flex>
            <DDataTable
                columns={[
                    { name: 'ID', key: 'id', align: 'center' },
                    { name: t('Type'), key: 'type', enumObj: InstructionTypeEnum },
                    { name: t('Title'), key: 'title' },
                    {
                        name: t('Content'),
                        key: 'content',
                        render: (rowData) => {
                            let url = rowData.content,
                                text = t('Open {{type}}', {
                                    type: EnumHelper.getText(InstructionTypeEnum, rowData.type).toLowerCase(),
                                });
                            if (rowData.type === InstructionTypeEnum.IMAGE.value) {
                                url = UrlHelper.getStaticUrl(rowData.content);
                                text = t('View image');
                            }

                            return (
                                <Anchor href={url} target="_blank">
                                    <Flex gap={4} align="center">
                                        {text}
                                        <LuExternalLink />
                                    </Flex>
                                </Anchor>
                            );
                        },
                    },
                    {
                        name: t('Action'),
                        key: 'action',
                        render: (rowData) => (
                            <Flex>
                                <ActionIcon
                                    variant="subtle"
                                    color="blue"
                                    onClick={() => {
                                        setCurrentInstruction(rowData);
                                        createUpdateModalAction.open();
                                    }}
                                    disabled={!perms.WORKPLACE_INSTRUCTION_EDIT}
                                >
                                    <LuPencilLine size={16} />
                                </ActionIcon>
                                <ActionIcon
                                    variant="subtle"
                                    color="red"
                                    onClick={() => setDeleteId(rowData.id)}
                                    disabled={!perms.WORKPLACE_INSTRUCTION_DELETE}
                                >
                                    <LuTrash2 size={16} />
                                </ActionIcon>
                            </Flex>
                        ),
                        width: 100,
                    },
                ]}
                data={data && data.data ? data.data : []}
                isFetching={isLoading}
            />
            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete instruction')}
                message={t('Are you sure you want to delete this instruction?')}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={deleteMutation.isPending}
                onCancel={() => setDeleteId(null)}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deleteMutation.mutateAsync(deleteId.toString());
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted instruction'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete instruction'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
            />
            <WorkplaceInstructionCreateUpdate
                workplaceId={workplaceId}
                instruction={currentInstruction}
                opened={createUpdateModalOpened}
                onClose={createUpdateModalAction.close}
            />
        </Paper>
    );
};
export default WorkplaceInstructionList;
