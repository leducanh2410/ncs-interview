import React from 'react';
import { useTranslation } from 'react-i18next';
import { LuFile, LuFileText, LuImage, LuUndo2 } from 'react-icons/lu';
import { ActionIcon, Anchor, Checkbox, Text } from '@mantine/core';
import { UserPaperItem } from '@/types/models/user_paper';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';
import DateHelper from '@/helpers/date.helper';
import EnumHelper from '@/helpers/enum.helper';
import UrlHelper from '@/helpers/url.helper';
import { getFileCategory } from '@/pages/admin/user/partials/paper_preview_modal';

type Props = {
    item: UserPaperItem;
    isSelected: boolean;
    isSelectionDisabled: boolean;
    onContextMenu: (event: React.MouseEvent<HTMLDivElement>, item: UserPaperItem) => void;
    onPreview: (item: UserPaperItem) => void;
    onRestore: (id: number) => void;
    onToggleSelect: (item: UserPaperItem) => void;
};

const FileIcon = ({ fileName }: { fileName: string }) => {
    const category = getFileCategory(fileName);

    if (category === 'image') return <LuImage size={18} color="var(--mantine-color-blue-6)" />;
    if (category === 'pdf') return <LuFileText size={18} color="var(--mantine-color-red-6)" />;

    return <LuFile size={18} color="var(--mantine-color-gray-6)" />;
};

const rowStyle: React.CSSProperties = {
    alignItems: 'center',
    borderBottom: '1px solid var(--mantine-color-gray-2)',
    display: 'flex',
    gap: 12,
    paddingBlock: 8,
    paddingInline: 12,
};

const PaperFileRow = ({
    item,
    isSelected,
    isSelectionDisabled,
    onContextMenu,
    onPreview,
    onRestore,
    onToggleSelect,
}: Props) => {
    const { t } = useTranslation();
    const fileCategory = getFileCategory(item.file_name);
    const canPreview = fileCategory === 'image' || fileCategory === 'pdf';
    const fileUrl = UrlHelper.getStaticUrl(item.file_path);
    const isDeleted = item.folder === 'deleted';

    return (
        <div
            onContextMenu={(event) => onContextMenu(event, item)}
            style={{
                ...rowStyle,
                backgroundColor: isSelected ? 'var(--mantine-color-blue-0)' : undefined,
            }}
        >
            <Checkbox
                checked={isSelected}
                disabled={isSelectionDisabled}
                onChange={() => onToggleSelect(item)}
                onClick={(event) => event.stopPropagation()}
            />

            <FileIcon fileName={item.file_name} />

            <Text
                size="sm"
                fw={500}
                style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
            >
                {item.file_name}
            </Text>

            <Text size="sm" c="dimmed" w={220} style={{ flexShrink: 0 }}>
                {EnumHelper.getText(UserPaperTypeEnum, item.type)}
            </Text>

            {canPreview ? (
                <Anchor
                    component="button"
                    type="button"
                    size="sm"
                    w={90}
                    style={{ flexShrink: 0, textAlign: 'left' }}
                    onClick={(event) => {
                        event.stopPropagation();
                        onPreview(item);
                    }}
                >
                    {t('Preview')}
                </Anchor>
            ) : (
                <Anchor
                    href={fileUrl}
                    target="_blank"
                    rel="noopener noreferrer"
                    download={item.file_name}
                    size="sm"
                    w={90}
                    style={{ flexShrink: 0 }}
                    onClick={(event) => event.stopPropagation()}
                >
                    {t('Download')}
                </Anchor>
            )}

            <Text size="sm" c="dimmed" w={150} style={{ flexShrink: 0 }}>
                {DateHelper.parseAndFormat(item.created_at, null, t('FORMAT_TIMESTAMP'))}
            </Text>

            {isDeleted && (
                <ActionIcon
                    variant="subtle"
                    color="blue"
                    onClick={(event) => {
                        event.stopPropagation();
                        onRestore(item.id);
                    }}
                    style={{ flexShrink: 0 }}
                >
                    <LuUndo2 size={16} />
                </ActionIcon>
            )}
        </div>
    );
};

export default PaperFileRow;
