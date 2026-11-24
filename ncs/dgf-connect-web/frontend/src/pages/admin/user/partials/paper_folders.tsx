import React, { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuFolder, LuFolderInput, LuFolderOpen, LuUndo2 } from 'react-icons/lu';
import { Badge, Button, Checkbox, Flex, Menu, Paper, Text, Title, UnstyledButton } from '@mantine/core';
import { notifications } from '@mantine/notifications';
import { UserPaperItem } from '@/types/models/user_paper';
import DConfirmModal from '@/components/DConfirmModal';
import PaperApprovalFolders from '@/pages/admin/user/partials/paper_approval_folders';
import PaperFileRow from '@/pages/admin/user/partials/paper_file_row';
import PaperPreviewModal from '@/pages/admin/user/partials/paper_preview_modal';
import { useMoveUserPaperMutation, useRestoreUserPaperMutation } from '@/pages/admin/user/api';

type FolderType = 'current' | 'old' | 'rejected' | 'deleted';
type MovableFolderType = Extract<FolderType, 'current' | 'old'>;

type FolderConfig = {
    key: FolderType;
    label: string;
    color: string;
};

type ContextMenuState = {
    actionItemIds: number[];
    sourceFolder: FolderType;
    x: number;
    y: number;
};

type Props = {
    title: string;
    items: UserPaperItem[];
    userId: string;
    confirmedApprovalAt?: string | null;
};

const isMovableFolder = (folder: UserPaperItem['folder']): folder is MovableFolderType =>
    folder === 'current' || folder === 'old';

// Only moving and restoring act on a selection, so selecting in any other folder leads nowhere
const hasFolderActions = (folder: UserPaperItem['folder']) => isMovableFolder(folder) || folder === 'deleted';

const getTargetFolder = (folder: MovableFolderType): MovableFolderType => (folder === 'current' ? 'old' : 'current');

const getInitialSelectedIdsByFolder = (): Record<FolderType, number[]> => ({
    current: [],
    old: [],
    rejected: [],
    deleted: [],
});

const PaperFolders = ({ title, items, userId, confirmedApprovalAt = null }: Props) => {
    const { t } = useTranslation();
    const moveMutation = useMoveUserPaperMutation(userId);
    const restoreMutation = useRestoreUserPaperMutation(userId);
    const [previewItem, setPreviewItem] = useState<UserPaperItem | null>(null);
    const [restoreItemIds, setRestoreItemIds] = useState<number[]>([]);
    const [localItems, setLocalItems] = useState<UserPaperItem[]>([]);
    const [selectedIdsByFolder, setSelectedIdsByFolder] =
        useState<Record<FolderType, number[]>>(getInitialSelectedIdsByFolder);
    const [expandedFolders, setExpandedFolders] = useState<Set<FolderType>>(new Set(['current']));
    const [contextMenu, setContextMenu] = useState<ContextMenuState | null>(null);
    const [isMoving, setIsMoving] = useState(false);
    const [isRestoring, setIsRestoring] = useState(false);

    const effectiveItems = localItems.length > 0 || items.length === 0 ? localItems : items;

    React.useEffect(() => {
        setLocalItems(items);
    }, [items]);

    const itemMap = useMemo(() => new Map(effectiveItems.map((item) => [item.id, item])), [effectiveItems]);

    React.useEffect(() => {
        setSelectedIdsByFolder((prev) => ({
            current: prev.current.filter((id) => itemMap.get(id)?.folder === 'current'),
            old: prev.old.filter((id) => itemMap.get(id)?.folder === 'old'),
            rejected: prev.rejected.filter((id) => itemMap.get(id)?.folder === 'rejected'),
            deleted: prev.deleted.filter((id) => itemMap.get(id)?.folder === 'deleted'),
        }));
    }, [itemMap]);

    const folders: FolderConfig[] = useMemo(
        () => [
            { key: 'current', label: t('Current papers'), color: 'teal' },
            { key: 'old', label: t('Old papers'), color: 'yellow' },
            { key: 'rejected', label: t('Rejected papers'), color: 'red' },
            { key: 'deleted', label: t('Deleted papers'), color: 'gray' },
        ],
        [t],
    );

    const grouped = useMemo(() => {
        const result: Record<FolderType, UserPaperItem[]> = {
            current: [],
            old: [],
            rejected: [],
            deleted: [],
        };

        effectiveItems.forEach((item) => {
            if (item.folder && result[item.folder]) {
                result[item.folder].push(item);
            }
        });

        return result;
    }, [effectiveItems]);

    const toggleFolder = useCallback((key: FolderType) => {
        setExpandedFolders((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    }, []);

    const closeContextMenu = useCallback(() => {
        setContextMenu(null);
    }, []);

    React.useEffect(() => {
        if (!contextMenu) return;

        const handleClickOutside = () => closeContextMenu();
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                closeContextMenu();
            }
        };

        window.addEventListener('click', handleClickOutside);
        window.addEventListener('keydown', handleKeyDown);

        return () => {
            window.removeEventListener('click', handleClickOutside);
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [closeContextMenu, contextMenu]);

    const handleMoveItems = useCallback(
        async (itemIds: number[], sourceFolder: MovableFolderType, targetFolder: MovableFolderType) => {
            const uniqueItemIds = [...new Set(itemIds)];
            if (uniqueItemIds.length === 0) return;

            closeContextMenu();
            setIsMoving(true);

            try {
                await moveMutation.mutateAsync({ ids: uniqueItemIds, folder: targetFolder });
                notifications.show({
                    color: 'teal',
                    message: uniqueItemIds.length > 1 ? t('Successfully moved papers') : t('Successfully moved paper'),
                });
            } catch {
                notifications.show({
                    color: 'red',
                    message: uniqueItemIds.length > 1 ? t('Could not move papers') : t('Could not move paper'),
                });
            } finally {
                setIsMoving(false);
            }
        },
        [closeContextMenu, moveMutation, t],
    );

    const handleRestore = async () => {
        if (restoreItemIds.length === 0) return;

        try {
            setIsRestoring(true);
            const uniqueItemIds = [...new Set(restoreItemIds)];
            await restoreMutation.mutateAsync(uniqueItemIds);
            notifications.show({ color: 'teal', message: t('Successfully restored user paper') });
        } catch {
            notifications.show({ color: 'red', message: t('error|Could not restore user paper') });
        } finally {
            setIsRestoring(false);
            setRestoreItemIds([]);
        }
    };

    const handleToggleSelect = useCallback((item: UserPaperItem) => {
        if (!item.folder) return;
        const folderKey = item.folder;

        setSelectedIdsByFolder((prev) => {
            const currentSelectedIds = prev[folderKey];
            const isSelected = currentSelectedIds.includes(item.id);

            if (isSelected) {
                return {
                    ...prev,
                    [folderKey]: currentSelectedIds.filter((id) => id !== item.id),
                };
            }

            return {
                ...prev,
                [folderKey]: [...currentSelectedIds, item.id],
            };
        });
    }, []);

    const handleRowContextMenu = useCallback(
        (event: React.MouseEvent<HTMLDivElement>, item: UserPaperItem) => {
            if (!item.folder) return;
            const sourceFolder = item.folder;

            // Rejected papers have no move or restore action, so our menu would come up empty.
            // Bail out before preventDefault so the browser's own menu still opens on the row.
            if (!hasFolderActions(sourceFolder)) return;

            event.preventDefault();
            event.stopPropagation();

            const selectedIds = selectedIdsByFolder[sourceFolder];
            const actionItemIds = selectedIds.length > 0 && selectedIds.includes(item.id) ? selectedIds : [item.id];

            setSelectedIdsByFolder((prev) => ({
                ...prev,
                [sourceFolder]: actionItemIds,
            }));
            setContextMenu({
                actionItemIds,
                sourceFolder,
                x: event.clientX,
                y: event.clientY,
            });
        },
        [selectedIdsByFolder],
    );

    return (
        <>
            <Flex align="center" mb={16} gap="sm">
                <Title order={3} size={16}>
                    {title}
                </Title>
            </Flex>

            <Flex direction="column" gap="sm">
                {folders.map((folder) => {
                    const folderItems = grouped[folder.key];
                    const isExpanded = expandedFolders.has(folder.key);
                    const movableFolderKey = isMovableFolder(folder.key) ? folder.key : null;
                    const selectedIds = selectedIdsByFolder[folder.key];
                    const selectableIds = folderItems.map((item) => item.id);
                    const canRestore = folder.key === 'deleted';
                    const canSelect = hasFolderActions(folder.key);
                    const isAllSelected = selectableIds.length > 0 && selectedIds.length === selectableIds.length;
                    const isPartiallySelected = selectedIds.length > 0 && selectedIds.length < selectableIds.length;
                    const selectedCountLabel =
                        selectedIds.length === 1
                            ? t('{{count}} selected paper', { count: selectedIds.length })
                            : t('{{count}} selected papers', { count: selectedIds.length });
                    const moveButtonLabel =
                        movableFolderKey === 'current' ? t('Move to Old papers') : t('Move to Current papers');

                    return (
                        <Paper key={folder.key} withBorder radius="md" style={{ overflow: 'hidden' }}>
                            <UnstyledButton
                                w="100%"
                                px="md"
                                py="sm"
                                onClick={() => toggleFolder(folder.key)}
                                style={{
                                    borderBottom: isExpanded ? '1px solid var(--mantine-color-gray-2)' : undefined,
                                    minHeight: 68,
                                }}
                            >
                                <Flex align="center" justify="space-between" gap="sm">
                                    <Flex align="center" gap="sm" style={{ flex: 1, minWidth: 0 }}>
                                        <Checkbox
                                            checked={isAllSelected}
                                            disabled={!canSelect}
                                            indeterminate={isPartiallySelected}
                                            onChange={(event) => {
                                                event.stopPropagation();
                                                const { checked } = event.currentTarget;
                                                setSelectedIdsByFolder((prev) => ({
                                                    ...prev,
                                                    [folder.key]: checked ? selectableIds : [],
                                                }));
                                            }}
                                            onClick={(event) => event.stopPropagation()}
                                        />
                                        <LuFolder size={18} />
                                        <Text fw={500}>{folder.label}</Text>
                                        <Badge size="sm" color={folder.color} variant="light">
                                            {folderItems.length}
                                        </Badge>
                                    </Flex>
                                    <Flex align="center" gap="sm" wrap="wrap" justify="flex-end">
                                        {selectedIds.length > 0 && (
                                            <>
                                                {selectedIds.length > 0 && (
                                                    <Text size="sm" fw={500}>
                                                        {selectedCountLabel}
                                                    </Text>
                                                )}
                                                {movableFolderKey && (
                                                    <Button
                                                        size="sm"
                                                        variant="light"
                                                        loading={isMoving}
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            void handleMoveItems(
                                                                selectedIds,
                                                                movableFolderKey,
                                                                getTargetFolder(movableFolderKey),
                                                            );
                                                        }}
                                                    >
                                                        {moveButtonLabel}
                                                    </Button>
                                                )}
                                                {canRestore && (
                                                    <Button
                                                        size="sm"
                                                        variant="light"
                                                        color="blue"
                                                        leftSection={<LuUndo2 size={16} />}
                                                        loading={isRestoring}
                                                        onClick={(event) => {
                                                            event.stopPropagation();
                                                            setRestoreItemIds(selectedIds);
                                                        }}
                                                    >
                                                        {selectedIds.length > 1
                                                            ? t('Restore papers')
                                                            : t('Restore paper')}
                                                    </Button>
                                                )}
                                            </>
                                        )}
                                        <LuChevronDown
                                            size={16}
                                            style={{
                                                transition: 'transform 0.2s',
                                                transform: isExpanded ? 'rotate(180deg)' : undefined,
                                            }}
                                        />
                                    </Flex>
                                </Flex>
                            </UnstyledButton>

                            {isExpanded && (
                                <div>
                                    {folderItems.length === 0 && (
                                        <Text size="sm" c="dimmed" ta="center" py="md">
                                            {t('No papers in this folder')}
                                        </Text>
                                    )}

                                    {folderItems.map((item) => (
                                        <PaperFileRow
                                            key={item.id}
                                            item={item}
                                            isSelected={
                                                !!item.folder && selectedIdsByFolder[item.folder].includes(item.id)
                                            }
                                            isSelectionDisabled={!canSelect}
                                            onContextMenu={handleRowContextMenu}
                                            onRestore={(id) => setRestoreItemIds([id])}
                                            onPreview={setPreviewItem}
                                            onToggleSelect={handleToggleSelect}
                                        />
                                    ))}
                                </div>
                            )}
                        </Paper>
                    );
                })}
                <PaperApprovalFolders
                    items={effectiveItems}
                    confirmedApprovalAt={confirmedApprovalAt}
                    onPreview={setPreviewItem}
                />
            </Flex>

            {contextMenu && (
                <Menu
                    opened
                    onChange={(opened) => {
                        if (!opened) closeContextMenu();
                    }}
                    position="bottom-start"
                    shadow="md"
                    width={330}
                    withinPortal
                >
                    <Menu.Target>
                        <div
                            style={{
                                height: 1,
                                left: contextMenu.x,
                                position: 'fixed',
                                pointerEvents: 'none',
                                top: contextMenu.y,
                                width: 1,
                            }}
                        />
                    </Menu.Target>
                    <Menu.Dropdown>
                        {isMovableFolder(contextMenu.sourceFolder) && (
                            <Menu.Item
                                leftSection={
                                    contextMenu.sourceFolder === 'current' ? (
                                        <LuFolderInput size={16} />
                                    ) : (
                                        <LuFolderOpen size={16} />
                                    )
                                }
                                onClick={() => {
                                    if (!isMovableFolder(contextMenu.sourceFolder)) return;
                                    void handleMoveItems(
                                        contextMenu.actionItemIds,
                                        contextMenu.sourceFolder,
                                        getTargetFolder(contextMenu.sourceFolder),
                                    );
                                }}
                            >
                                {contextMenu.sourceFolder === 'current'
                                    ? t('Move to Old papers')
                                    : t('Move to Current papers')}
                            </Menu.Item>
                        )}
                        {contextMenu.sourceFolder === 'deleted' && (
                            <Menu.Item
                                color="blue"
                                leftSection={<LuUndo2 size={16} />}
                                onClick={() => {
                                    closeContextMenu();
                                    setRestoreItemIds(contextMenu.actionItemIds);
                                }}
                            >
                                {contextMenu.actionItemIds.length > 1 ? t('Restore papers') : t('Restore paper')}
                            </Menu.Item>
                        )}
                    </Menu.Dropdown>
                </Menu>
            )}

            <PaperPreviewModal opened={previewItem !== null} onClose={() => setPreviewItem(null)} item={previewItem} />

            <DConfirmModal
                opened={restoreItemIds.length > 0}
                title={restoreItemIds.length > 1 ? t('Restore papers') : t('Restore paper')}
                message={
                    restoreItemIds.length > 1
                        ? t('Are you sure you want to restore selected papers?')
                        : t('Are you sure you want to restore this paper?')
                }
                confirmButtonLabel={restoreItemIds.length > 1 ? t('Restore papers') : t('Restore paper')}
                confirmButtonColor="blue"
                onConfirm={handleRestore}
                onCancel={() => setRestoreItemIds([])}
                isProcessing={isRestoring}
            />
        </>
    );
};

export default PaperFolders;
