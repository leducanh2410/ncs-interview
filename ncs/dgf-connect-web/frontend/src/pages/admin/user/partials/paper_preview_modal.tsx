import React from 'react';
import { useTranslation } from 'react-i18next';
import { Anchor, Modal, Text } from '@mantine/core';
import { UserPaperItem } from '@/types/models/user_paper';
import { UserPaperTypeEnum } from '@/enums/user_paper_type.enum';
import EnumHelper from '@/helpers/enum.helper';
import UrlHelper from '@/helpers/url.helper';

type FileCategory = 'image' | 'pdf' | 'other';

const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

export const getFileCategory = (fileName: string): FileCategory => {
    const ext = fileName.split('.').pop()?.toLowerCase() ?? '';
    if (IMAGE_EXTENSIONS.includes(ext)) return 'image';
    if (ext === 'pdf') return 'pdf';
    return 'other';
};

type Props = {
    opened: boolean;
    onClose: () => void;
    item: UserPaperItem | null;
};

const PaperPreviewModal = ({ opened, onClose, item }: Props) => {
    const { t } = useTranslation();

    if (!item) return null;

    const category = getFileCategory(item.file_name);
    const staticUrl = UrlHelper.getStaticUrl(item.file_path);
    const typeLabel = EnumHelper.getText(UserPaperTypeEnum, item.type);

    return (
        <Modal opened={opened} onClose={onClose} title={`${typeLabel} — ${item.file_name}`} size="xl">
            {category === 'image' && (
                <img
                    src={staticUrl}
                    alt={item.file_name}
                    style={{
                        maxWidth: '100%',
                        maxHeight: '80vh',
                        objectFit: 'contain',
                        display: 'block',
                        margin: '0 auto',
                    }}
                />
            )}
            {category === 'pdf' && (
                <iframe
                    src={staticUrl}
                    style={{ width: '100%', height: '80vh', border: 'none' }}
                    title={item.file_name}
                />
            )}
            {category === 'other' && (
                <Text ta="center" py="xl">
                    <Anchor href={staticUrl} target="_blank">
                        {t('Download file')}: {item.file_name}
                    </Anchor>
                </Text>
            )}
        </Modal>
    );
};

export default PaperPreviewModal;
