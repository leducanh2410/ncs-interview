import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuFileText, LuGripVertical } from 'react-icons/lu';
import Link from 'next/link';
import { DragDropContext, Draggable, type DropResult, Droppable } from '@hello-pangea/dnd';
import { Button, Checkbox, Flex, Group, Modal, Select, Text } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { CsvPropertyMeta } from '@/types/models/csv_export';
import type { UserExportFilters } from '@/types/user_export';
import { Encoding, EncodingEnum } from '@/enums/encoding.enum';
import { ApiError } from '@/helpers/api.helper';
import NotificationHelper from '@/helpers/notification.helper';
import RouteHelper from '@/helpers/route.helper';
import { getCsvPropertyLabel } from '@/pages/admin/csv-export/csv_property_label';
import { usePermissions } from '@/hooks/usePermission';
import { useCreateCsvExportMutation, useGetCsvPropertiesQuery } from '@/pages/admin/csv-export/api';

type Props = {
    isOpened: boolean;
    onClose: () => void;
    selectedUserIds: number[];
    selectAll: boolean;
    totalUsers: number;
    filters: UserExportFilters;
};

const USER_CSV_EXPORT_CONFIG_KEY = 'user_csv_export_config';

type CsvExportConfig = {
    selected: string[];
    order: string[];
    encoding: Encoding;
};

const ExportCsvModal = ({ isOpened, onClose, selectedUserIds, selectAll, totalUsers, filters }: Props) => {
    const { t } = useTranslation();
    const perms = usePermissions(['USER_EXPORT_CSV']);
    const [orderedItems, setOrderedItems] = useState<CsvPropertyMeta[]>([]);
    const [selectedKeys, setSelectedKeys] = useState<Set<string>>(new Set());
    const [selectedEncoding, setSelectedEncoding] = useState<Encoding>('UTF_8');

    const { data: propertiesData, isPending: isPropertiesPending } = useGetCsvPropertiesQuery(perms.USER_EXPORT_CSV);
    const createBatchMutation = useCreateCsvExportMutation();

    const canonicalKeys = useMemo(() => {
        return (propertiesData?.data || []).map((p) => p.key);
    }, [propertiesData]);

    const defaultConfig = useMemo((): CsvExportConfig => {
        return {
            selected: [...canonicalKeys],
            order: [...canonicalKeys],
            encoding: 'UTF_8',
        };
    }, [canonicalKeys]);

    useEffect(() => {
        if (!isOpened || canonicalKeys.length === 0) return;

        try {
            const stored = localStorage.getItem(USER_CSV_EXPORT_CONFIG_KEY);
            if (stored) {
                const parsed: CsvExportConfig = JSON.parse(stored);
                const storedOrder = parsed.order || [];
                const storedSelected = new Set(parsed.selected || []);

                const knownOrder = storedOrder.filter((k) => canonicalKeys.includes(k));
                const missingKeys = canonicalKeys.filter((k) => !knownOrder.includes(k));
                const reconciledOrder = [...knownOrder, ...missingKeys];
                const reconciledSelected = new Set(Array.from(storedSelected).filter((k) => canonicalKeys.includes(k)));

                setOrderedItems(
                    reconciledOrder.map((key) => {
                        const prop = propertiesData?.data?.find((p) => p.key === key);
                        return prop || { key, label_ja: key };
                    }),
                );
                setSelectedKeys(reconciledSelected);
                setSelectedEncoding(EncodingEnum[parsed.encoding] ? parsed.encoding : 'UTF_8');
            } else {
                setOrderedItems(propertiesData?.data || []);
                setSelectedKeys(new Set(canonicalKeys));
                setSelectedEncoding('UTF_8');
            }
        } catch {
            setOrderedItems(propertiesData?.data || []);
            setSelectedKeys(new Set(canonicalKeys));
            setSelectedEncoding('UTF_8');
        }
    }, [isOpened, canonicalKeys, propertiesData]);

    const userCount = selectAll ? totalUsers : selectedUserIds.length;
    const selectedCount = selectedKeys.size;

    const handleToggleKey = (key: string) => {
        setSelectedKeys((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const handleDragEnd = (result: DropResult) => {
        if (!result.destination) return;
        const sourceIndex = result.source.index;
        const destinationIndex = result.destination.index;
        if (sourceIndex === destinationIndex) return;

        const newItems = Array.from(orderedItems);
        const [reorderedItem] = newItems.splice(sourceIndex, 1);
        newItems.splice(destinationIndex, 0, reorderedItem);
        setOrderedItems(newItems);
    };

    const handleResetToDefault = () => {
        setOrderedItems(propertiesData?.data || []);
        setSelectedKeys(new Set(canonicalKeys));
        setSelectedEncoding('UTF_8');
    };

    const handleSubmit = async () => {
        const orderedKeys = orderedItems.map((item) => item.key);
        const selectedKeysArray = Array.from(selectedKeys);

        if (selectedKeysArray.length === 0) {
            NotificationHelper.showError(t('Select at least one property'));
            return;
        }

        if (userCount === 0) {
            NotificationHelper.showError(t('No users selected'));
            return;
        }

        try {
            const config: CsvExportConfig = {
                selected: selectedKeysArray,
                order: orderedKeys,
                encoding: selectedEncoding,
            };
            localStorage.setItem(USER_CSV_EXPORT_CONFIG_KEY, JSON.stringify(config));

            const encodingValue = EncodingEnum[selectedEncoding].value;
            const payload = selectAll
                ? {
                      selected_keys: selectedKeysArray,
                      ordered_keys: orderedKeys,
                      encoding: encodingValue,
                      select_all: true,
                      filters,
                  }
                : {
                      selected_keys: selectedKeysArray,
                      ordered_keys: orderedKeys,
                      encoding: encodingValue,
                      user_ids: selectedUserIds,
                      select_all: false,
                  };

            const response = await createBatchMutation.mutateAsync(payload);

            if (response.data) {
                const batchId = response.data.id;
                onClose();
                notifications.show({
                    message: (
                        <Flex gap="xs" align="center">
                            <Text>{t('Export started successfully')}</Text>
                            <Link
                                href={`${RouteHelper.getPath('EXPORT_BATCH_DETAIL', { id: batchId })}?type=csv`}
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
                NotificationHelper.showError(error.message || t('Failed to start export'));
            } else {
                NotificationHelper.showError(t('Failed to start export'));
            }
        }
    };

    const handleClose = () => {
        onClose();
    };

    return (
        <Modal opened={isOpened} onClose={handleClose} title={t('Export CSV')} size="md">
            <Flex direction="column" gap="md">
                <Text>
                    {selectAll
                        ? t('Exporting for all {{count}} users matching current filters', { count: userCount })
                        : t('Exporting for {{count}} selected users', { count: userCount })}
                </Text>

                <Flex justify="space-between" align="center">
                    <Button
                        variant="subtle"
                        size="xs"
                        onClick={handleResetToDefault}
                        disabled={createBatchMutation.isPending}
                    >
                        {t('Reset to default')}
                    </Button>
                    <Text size="sm" c="dimmed">
                        {t('{{selected}} / {{total}} selected', {
                            selected: selectedCount,
                            total: orderedItems.length,
                        })}
                    </Text>
                </Flex>

                {isPropertiesPending ? (
                    <Text c="dimmed" size="sm">
                        {t('Loading properties...')}
                    </Text>
                ) : (
                    <DragDropContext onDragEnd={handleDragEnd}>
                        <Droppable droppableId="csv-properties">
                            {(provided) => (
                                <div
                                    {...provided.droppableProps}
                                    ref={provided.innerRef}
                                    style={{ maxHeight: '400px', overflowY: 'auto' }}
                                >
                                    {orderedItems.map((item, index) => (
                                        <Draggable key={item.key} draggableId={item.key} index={index}>
                                            {(provided, snapshot) => (
                                                <Flex
                                                    ref={provided.innerRef}
                                                    {...provided.draggableProps}
                                                    style={{
                                                        ...provided.draggableProps.style,
                                                        backgroundColor: snapshot.isDragging
                                                            ? 'var(--mantine-color-gray-0)'
                                                            : undefined,
                                                        borderBottom: '1px solid var(--mantine-color-gray-2)',
                                                    }}
                                                    align="center"
                                                    gap="sm"
                                                    p="xs"
                                                >
                                                    <div
                                                        {...provided.dragHandleProps}
                                                        style={{ cursor: 'grab', display: 'flex' }}
                                                    >
                                                        <LuGripVertical size={16} />
                                                    </div>
                                                    <Checkbox
                                                        checked={selectedKeys.has(item.key)}
                                                        onChange={() => handleToggleKey(item.key)}
                                                        disabled={createBatchMutation.isPending}
                                                    />
                                                    <Text size="sm" style={{ flex: 1 }}>
                                                        {getCsvPropertyLabel(item.key, t)}
                                                    </Text>
                                                </Flex>
                                            )}
                                        </Draggable>
                                    ))}
                                    {provided.placeholder}
                                </div>
                            )}
                        </Droppable>
                    </DragDropContext>
                )}

                <Select
                    label={t('Encoding')}
                    value={EncodingEnum[selectedEncoding].value}
                    onChange={(value) => {
                        const key = (Object.keys(EncodingEnum) as Encoding[]).find(
                            (k) => EncodingEnum[k].value === value,
                        );
                        if (key) setSelectedEncoding(key);
                    }}
                    data={[
                        { value: EncodingEnum.UTF_8.value, label: EncodingEnum.UTF_8.text },
                        { value: EncodingEnum.SHIFT_JIS.value, label: EncodingEnum.SHIFT_JIS.text },
                    ]}
                    disabled={createBatchMutation.isPending}
                />

                <Group justify="flex-end" mt="md">
                    <Button variant="default" onClick={handleClose} disabled={createBatchMutation.isPending}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        leftSection={<LuFileText />}
                        onClick={handleSubmit}
                        loading={createBatchMutation.isPending}
                        disabled={selectedKeys.size === 0 || userCount === 0 || isPropertiesPending}
                    >
                        {t('Export')}
                    </Button>
                </Group>
            </Flex>
        </Modal>
    );
};

export default ExportCsvModal;
