import { useLayoutEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuPin, LuX } from 'react-icons/lu';
import { ActionIcon, Box, Group, Stack, Text } from '@mantine/core';
import { ChatMessage } from '@/types/models/chat';
import classes from './chat.module.css';

type PinnedMessageBannerProps = {
    message: ChatMessage;
    isAdmin: boolean;
    onUnpin: () => void;
    isUnpinning?: boolean;
};

const PinnedMessageBanner = ({ message, isAdmin, onUnpin, isUnpinning = false }: PinnedMessageBannerProps) => {
    const { t } = useTranslation();
    const [expanded, setExpanded] = useState(false);
    const [isOverflowing, setIsOverflowing] = useState(false);
    const contentRef = useRef<HTMLDivElement | null>(null);

    const body = message.body ?? '';
    const hasAttachments = (message.attachments ?? []).length > 0;
    const previewText = body || (hasAttachments ? t('Attachment') : t('Message'));

    useLayoutEffect(() => {
        if (expanded) return;
        const el = contentRef.current;
        if (!el) return;
        setIsOverflowing(el.scrollHeight > el.clientHeight);
    }, [body, expanded]);

    const handleExpandToggle = (e: React.MouseEvent) => {
        e.stopPropagation();
        setExpanded((prev) => !prev);
    };

    return (
        <Box className={classes.pinnedBanner}>
            <Group gap={8} align="flex-start" wrap="nowrap">
                <Box className={classes.pinnedIcon}>
                    <LuPin size={13} />
                </Box>

                <Stack gap={0} style={{ flex: 1, minWidth: 0 }}>
                    <Text size="xs" fw={600} c="blue.7" mb={2}>
                        {t('Pinned message')}
                    </Text>
                    <Box
                        ref={contentRef}
                        className={`${classes.pinnedContent}${expanded ? ` ${classes.pinnedContentExpanded}` : ''}`}
                    >
                        <Text size="sm" c="dark.7" style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                            {previewText}
                        </Text>
                    </Box>
                    {isOverflowing && (
                        <Text
                            size="xs"
                            c="blue.6"
                            fw={500}
                            mt={2}
                            style={{ cursor: 'pointer', userSelect: 'none', alignSelf: 'flex-start' }}
                            onClick={handleExpandToggle}
                        >
                            {expanded ? t('Collapse') : t('See more')}
                        </Text>
                    )}
                </Stack>

                {isAdmin && (
                    <ActionIcon
                        variant="subtle"
                        color="gray"
                        size="sm"
                        title={t('Unpin')}
                        loading={isUnpinning}
                        onClick={(e) => {
                            e.stopPropagation();
                            onUnpin();
                        }}
                    >
                        <LuX size={14} />
                    </ActionIcon>
                )}
            </Group>
        </Box>
    );
};

export default PinnedMessageBanner;
