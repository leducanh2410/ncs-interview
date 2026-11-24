import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuGripVertical, LuPencilLine, LuPlus, LuSave, LuTrash2 } from 'react-icons/lu';
import { DragDropContext, Draggable, type DropResult, Droppable } from '@hello-pangea/dnd';
import { ActionIcon, Button, Center, Flex, Paper, TableTd, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { notifications } from '@mantine/notifications';
import clsx from 'clsx';
import type { DataTableColumn } from 'mantine-datatable';
import { DataTable, DataTableDraggableRow } from 'mantine-datatable';
import { WorkplacePosition } from '@/types/models/workplace_position';
import DConfirmModal from '@/components/DConfirmModal';
import TableEmptyState from '@/components/TableEmptyState';
import WorkplacePositionCreateUpdate from '@/pages/admin/workplace/partials/workplace_position_create_update';
import classes from './workplace_position.module.css';
import { usePermissions } from '@/hooks/usePermission';
import {
    useDeleteWorkplacePositionMutation,
    useGetWorkplacePositionsQuery,
    useReorderWorkplacePositionsMutation,
} from '@/pages/admin/workplace/api';

type Props = {
    workplaceId: string;
};

const WorkplacePositionList = ({ workplaceId }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions([
        'WORKPLACE_POSITION_LIST',
        'WORKPLACE_POSITION_CREATE',
        'WORKPLACE_POSITION_EDIT',
        'WORKPLACE_POSITION_DELETE',
    ]);
    const { data, isLoading } = useGetWorkplacePositionsQuery(workplaceId, perms.WORKPLACE_POSITION_LIST);
    const deletePositionMutation = useDeleteWorkplacePositionMutation(workplaceId);
    const reorderMutation = useReorderWorkplacePositionsMutation(workplaceId);
    const [deleteId, setDeleteId] = useState<number | null>(null);
    const [currentPosition, setCurrentPosition] = useState<WorkplacePosition | null>(null);
    const [createUpdateModalOpened, createUpdateModalAction] = useDisclosure(false);
    const [items, setItems] = useState<WorkplacePosition[]>([]);
    const [originalIds, setOriginalIds] = useState<string[]>([]);

    useEffect(() => {
        if (data?.data) {
            setItems(data.data);
            setOriginalIds(data.data.map((i) => i.id.toString()));
        }
    }, [data]);

    const ids = useMemo(() => items.map((i) => i.id.toString()), [items]);

    const isOrderChanged = useMemo(() => {
        if (ids.length !== originalIds.length) return true;
        for (let i = 0; i < ids.length; i++) {
            if (ids[i] !== originalIds[i]) return true;
        }
        return false;
    }, [ids, originalIds]);

    const handleDragEnd = (result: DropResult) => {
        if (!result.destination) return;

        const sourceIndex = result.source.index;
        const destinationIndex = result.destination.index;

        if (sourceIndex === destinationIndex) return;

        const newItems = Array.from(items);
        const [reorderedItem] = newItems.splice(sourceIndex, 1);
        newItems.splice(destinationIndex, 0, reorderedItem);

        setItems(newItems);
    };

    const columns: DataTableColumn<WorkplacePosition>[] = [
        { accessor: '', hiddenContent: true, width: 30 },
        { accessor: 'id', title: 'ID', width: 80 },
        { accessor: 'name', title: t('Position'), width: 200 },
        {
            accessor: 'salary_per_hour',
            title: t('Salary per hour'),
            width: 150,
            render: (position) => (position.salary_per_hour === null ? '-' : position.salary_per_hour),
        },
        {
            accessor: 'actions',
            title: t('Action'),
            width: 100,
            render: (position) => (
                <Flex>
                    <ActionIcon
                        variant="subtle"
                        color="blue"
                        onClick={() => {
                            setCurrentPosition(position);
                            createUpdateModalAction.open();
                        }}
                        disabled={!perms.WORKPLACE_POSITION_EDIT}
                    >
                        <LuPencilLine size={16} />
                    </ActionIcon>
                    <ActionIcon
                        variant="subtle"
                        color="red"
                        onClick={() => setDeleteId(position.id)}
                        disabled={!perms.WORKPLACE_POSITION_DELETE}
                    >
                        <LuTrash2 size={16} />
                    </ActionIcon>
                </Flex>
            ),
        },
    ];

    return (
        <Paper flex={1} p="md">
            <Flex justify="space-between" align="center" mb="16">
                <Text size="md" fw={700}>
                    {t('Workplace position')}
                </Text>
                <Flex gap={12}>
                    <Button
                        size="sm"
                        h={32}
                        color="teal"
                        leftSection={<LuSave />}
                        disabled={!isOrderChanged || reorderMutation.isPending || !perms.WORKPLACE_POSITION_EDIT}
                        loading={reorderMutation.isPending}
                        onClick={async () => {
                            try {
                                await reorderMutation.mutateAsync(items.map((p) => p.id));
                                notifications.show({ color: 'teal', message: t('Successfully updated order') });
                                setOriginalIds(items.map((p) => p.id.toString()));
                            } catch (e) {
                                notifications.show({ color: 'red', message: t('error|Could not update order') });
                                // revert
                                if (data?.data) {
                                    setItems(data.data);
                                    setOriginalIds(data.data.map((i) => i.id.toString()));
                                }
                            }
                        }}
                    >
                        {t('Save order')}
                    </Button>
                    <Button
                        size="sm"
                        h={32}
                        leftSection={<LuPlus />}
                        disabled={!perms.WORKPLACE_POSITION_CREATE}
                        onClick={() => {
                            setCurrentPosition(null);
                            createUpdateModalAction.open();
                        }}
                    >
                        {t('Add')}
                    </Button>
                </Flex>
            </Flex>

            <DragDropContext onDragEnd={handleDragEnd}>
                <DataTable<WorkplacePosition>
                    columns={columns}
                    records={items}
                    fetching={isLoading}
                    withTableBorder
                    withColumnBorders
                    tableWrapper={({ children }) => (
                        <Droppable droppableId="datatable">
                            {(provided) => (
                                <div {...provided.droppableProps} ref={provided.innerRef}>
                                    {children}
                                    <div style={{ backgroundColor: 'red' }}>{provided.placeholder}</div>
                                </div>
                            )}
                        </Droppable>
                    )}
                    classNames={{
                        header: classes.header,
                    }}
                    emptyState={<TableEmptyState />}
                    minHeight={!items.length ? 150 : undefined}
                    styles={{ table: { tableLayout: 'fixed' } }}
                    rowFactory={({ record, index, rowProps, children }) => (
                        <Draggable
                            key={record.id.toString()}
                            draggableId={record.id.toString()}
                            index={index}
                            isDragDisabled={!perms.WORKPLACE_POSITION_EDIT}
                        >
                            {(provided, snapshot) => (
                                <DataTableDraggableRow
                                    isDragging={snapshot.isDragging}
                                    {...rowProps}
                                    className={clsx(rowProps.className, classes.draggableRow)}
                                    {...provided.draggableProps}
                                >
                                    <TableTd>
                                        <Center {...provided.dragHandleProps} ref={provided.innerRef}>
                                            <LuGripVertical
                                                size={16}
                                                cursor={snapshot.isDragging ? 'grabbing' : 'grab'}
                                            />
                                        </Center>
                                    </TableTd>
                                    {children}
                                </DataTableDraggableRow>
                            )}
                        </Draggable>
                    )}
                />
            </DragDropContext>

            <DConfirmModal
                opened={deleteId !== null}
                title={t('Delete position')}
                message={t('Are you sure you want to delete this position?')}
                confirmButtonLabel={t('Delete')}
                confirmButtonColor="red"
                isProcessing={deletePositionMutation.isPending}
                onCancel={() => setDeleteId(null)}
                onConfirm={async () => {
                    if (!deleteId) return;
                    try {
                        await deletePositionMutation.mutateAsync(deleteId.toString());
                        notifications.show({
                            color: 'teal',
                            message: t('Successfully deleted position'),
                        });
                    } catch (e) {
                        notifications.show({
                            color: 'red',
                            message: t('error|Could not delete position'),
                        });
                    } finally {
                        setDeleteId(null);
                    }
                }}
            />
            <WorkplacePositionCreateUpdate
                workplaceId={workplaceId}
                position={currentPosition}
                opened={createUpdateModalOpened}
                onClose={createUpdateModalAction.close}
            />
        </Paper>
    );
};
export default WorkplacePositionList;
