import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronLeft, LuChevronRight, LuDownload, LuImage, LuX } from 'react-icons/lu';
import { ActionIcon, Box, Center, Group, Loader, Modal, Text } from '@mantine/core';
import { ChatMessageAttachment } from '@/types/models/chat';
import classes from './chat.module.css';

const CELL_SIZE = 108;
const SINGLE_SIZE = 200;
const GAP = 2;
const MAX_GRID = 4;

// ── Image Cell ─────────────────────────────────────────────────────────────────

type CellProps = {
    attachment: ChatMessageAttachment;
    w: number;
    h: number;
    showOverlay?: boolean;
    extraCount?: number;
    onClick?: () => void;
    onFetchThumbnail: (attachment: ChatMessageAttachment) => Promise<Blob>;
};

const ImageCell = ({ attachment, w, h, showOverlay, extraCount = 0, onClick, onFetchThumbnail }: CellProps) => {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(false);
    const [blobUrl, setBlobUrl] = useState<string | null>(null);
    const onFetchRef = useRef(onFetchThumbnail);

    useEffect(() => {
        onFetchRef.current = onFetchThumbnail;
    });

    // Preview routes sit behind auth:api, so a plain <img src={url}> would be sent without the
    // bearer token and rejected with 401. Fetch the bytes with auth and render an object URL,
    // mirroring the lightbox/PDF preview pattern.
    useEffect(() => {
        let cancelled = false;
        let objectUrl: string | null = null;

        setLoading(true);
        setError(false);
        setBlobUrl(null);

        onFetchRef
            .current(attachment)
            .then((blob) => {
                if (cancelled) return;
                objectUrl = URL.createObjectURL(blob);
                setBlobUrl(objectUrl);
            })
            .catch(() => {
                if (cancelled) return;
                setLoading(false);
                setError(true);
            });

        return () => {
            cancelled = true;
            if (objectUrl) URL.revokeObjectURL(objectUrl);
        };
    }, [attachment.attachment_id, attachment.thumbnail_url]);

    return (
        <Box
            component={onClick ? 'button' : 'div'}
            type={onClick ? 'button' : undefined}
            className={classes.imageCell}
            style={{ width: w, height: h }}
            onClick={onClick}
        >
            {loading && !error && (
                <Center w={w} h={h}>
                    <Loader size="xs" />
                </Center>
            )}
            {error && (
                <Center w={w} h={h}>
                    <LuImage size={24} color="var(--mantine-color-gray-5)" />
                </Center>
            )}
            {blobUrl && !error && (
                <img
                    src={blobUrl}
                    alt=""
                    decoding="async"
                    style={{ width: w, height: h, objectFit: 'cover', display: loading || error ? 'none' : 'block' }}
                    onLoad={() => setLoading(false)}
                    onError={() => {
                        setLoading(false);
                        setError(true);
                    }}
                />
            )}
            {showOverlay && (
                <Box className={classes.imageOverlay}>
                    <Text fw={700} size="xl" c="white">
                        +{extraCount}
                    </Text>
                </Box>
            )}
        </Box>
    );
};

// ── Image Grid ─────────────────────────────────────────────────────────────────

type ChatImageGridProps = {
    attachments: ChatMessageAttachment[];
    onView: (index: number) => void;
    onFetchThumbnail: (attachment: ChatMessageAttachment) => Promise<Blob>;
};

export const ChatImageGrid = ({ attachments, onView, onFetchThumbnail }: ChatImageGridProps) => {
    const count = attachments.length;
    const extra = count > MAX_GRID ? count - MAX_GRID : 0;

    const cell = (idx: number, w: number, h = w, overlay = false) => (
        <ImageCell
            key={attachments[idx].id}
            attachment={attachments[idx]}
            w={w}
            h={h}
            showOverlay={overlay}
            extraCount={extra}
            onClick={() => onView(idx)}
            onFetchThumbnail={onFetchThumbnail}
        />
    );

    if (count === 1) {
        return (
            <Box className={classes.imageGrid} style={{ width: SINGLE_SIZE }}>
                {cell(0, SINGLE_SIZE)}
            </Box>
        );
    }

    if (count === 2) {
        return (
            <Box className={classes.imageGrid}>
                <Box className={classes.imageRow}>
                    {cell(0, CELL_SIZE)}
                    <Box w={GAP} />
                    {cell(1, CELL_SIZE)}
                </Box>
            </Box>
        );
    }

    if (count === 3) {
        return (
            <Box className={classes.imageGrid}>
                <Box className={classes.imageRow}>
                    {cell(0, CELL_SIZE)}
                    <Box w={GAP} />
                    {cell(1, CELL_SIZE)}
                </Box>
                <Box h={GAP} />
                <Box className={classes.imageRow}>{cell(2, CELL_SIZE * 2 + GAP, CELL_SIZE)}</Box>
            </Box>
        );
    }

    // 4 or more images: 2×2 grid, last cell shows "+N" overlay if more than 4
    return (
        <Box className={classes.imageGrid}>
            <Box className={classes.imageRow}>
                {cell(0, CELL_SIZE)}
                <Box w={GAP} />
                {cell(1, CELL_SIZE)}
            </Box>
            <Box h={GAP} />
            <Box className={classes.imageRow}>
                {cell(2, CELL_SIZE)}
                <Box w={GAP} />
                {cell(3, CELL_SIZE, CELL_SIZE, count > MAX_GRID)}
            </Box>
        </Box>
    );
};

// ── Image Viewer (Lightbox) ────────────────────────────────────────────────────

type ChatImageViewerProps = {
    attachments: ChatMessageAttachment[];
    initialIndex: number;
    onClose: () => void;
    onDownload: (attachment: ChatMessageAttachment) => Promise<void>;
    onFetchImage: (attachment: ChatMessageAttachment) => Promise<Blob>;
};

export const ChatImageViewer = ({
    attachments,
    initialIndex,
    onClose,
    onDownload,
    onFetchImage,
}: ChatImageViewerProps) => {
    const { t } = useTranslation();
    const [index, setIndex] = useState(initialIndex);
    const [downloadingCurrent, setDownloadingCurrent] = useState(false);
    const [imgLoading, setImgLoading] = useState(true);
    const [imgError, setImgError] = useState(false);
    const [blobUrl, setBlobUrl] = useState<string | null>(null);
    const blobCacheRef = useRef<Map<number, string>>(new Map());
    const onFetchImageRef = useRef(onFetchImage);

    useEffect(() => {
        onFetchImageRef.current = onFetchImage;
    });

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'ArrowLeft') setIndex((i) => Math.max(0, i - 1));
            else if (e.key === 'ArrowRight') setIndex((i) => Math.min(attachments.length - 1, i + 1));
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [attachments.length]);

    // Fetch full-size image for the current slide; cache blob URLs across navigation.
    useEffect(() => {
        const current = attachments[index];
        if (!current) return;

        setImgLoading(true);
        setImgError(false);

        const cached = blobCacheRef.current.get(current.attachment_id);
        if (cached) {
            setBlobUrl(cached);
            return;
        }

        setBlobUrl(null);
        let cancelled = false;

        onFetchImageRef
            .current(current)
            .then((blob) => {
                if (cancelled) return;
                const url = URL.createObjectURL(blob);
                blobCacheRef.current.set(current.attachment_id, url);
                setBlobUrl(url);
            })
            .catch(() => {
                if (cancelled) return;
                setImgLoading(false);
                setImgError(true);
            });

        return () => {
            cancelled = true;
        };
    }, [index, attachments]);

    // Revoke all blob URLs when the viewer closes.
    useEffect(() => {
        const cache = blobCacheRef.current;
        return () => {
            cache.forEach((url) => URL.revokeObjectURL(url));
        };
    }, []);

    const current = attachments[index];

    const handleDownloadCurrent = async () => {
        setDownloadingCurrent(true);
        try {
            await onDownload(current);
        } finally {
            setDownloadingCurrent(false);
        }
    };

    const goNext = () => {
        if (index < attachments.length - 1) setIndex((i) => i + 1);
    };

    const goPrev = () => {
        if (index > 0) setIndex((i) => i - 1);
    };

    return (
        <Modal
            opened
            onClose={onClose}
            size="xl"
            centered
            withCloseButton={false}
            padding={0}
            styles={{ content: { background: '#000', overflow: 'hidden' }, body: { padding: 0 } }}
        >
            {/* Header */}
            <Group justify="space-between" p="sm" style={{ background: 'rgba(0,0,0,0.75)' }}>
                <ActionIcon
                    variant="subtle"
                    c="white"
                    size="lg"
                    onClick={handleDownloadCurrent}
                    loading={downloadingCurrent}
                    aria-label={t('Download')}
                >
                    <LuDownload size={20} />
                </ActionIcon>
                <Text size="sm" c="white" fw={500}>
                    {index + 1} / {attachments.length}
                </Text>
                <ActionIcon variant="subtle" c="white" size="lg" onClick={onClose} aria-label={t('Close')}>
                    <LuX size={20} />
                </ActionIcon>
            </Group>

            {/* Image area */}
            <Box className={classes.viewerImageArea}>
                {index > 0 && (
                    <Box className={`${classes.viewerArrow} ${classes.viewerArrowLeft}`}>
                        <ActionIcon
                            size={52}
                            radius="xl"
                            variant="filled"
                            color="dark"
                            style={{ opacity: 0.85, boxShadow: '0 2px 12px rgba(0,0,0,0.5)' }}
                            onClick={goPrev}
                            aria-label={t('Previous')}
                        >
                            <LuChevronLeft size={28} />
                        </ActionIcon>
                    </Box>
                )}

                {imgLoading && !imgError && (
                    <Center h="100%">
                        <Loader color="white" />
                    </Center>
                )}
                {imgError && (
                    <Center h="100%">
                        <LuImage size={48} color="var(--mantine-color-gray-5)" />
                    </Center>
                )}
                {blobUrl && (
                    <img
                        key={index}
                        src={blobUrl}
                        alt=""
                        style={{
                            maxWidth: '100%',
                            maxHeight: '100%',
                            objectFit: 'contain',
                            display: imgLoading || imgError ? 'none' : 'block',
                        }}
                        onLoad={() => setImgLoading(false)}
                        onError={() => {
                            setImgLoading(false);
                            setImgError(true);
                        }}
                    />
                )}

                {index < attachments.length - 1 && (
                    <Box className={`${classes.viewerArrow} ${classes.viewerArrowRight}`}>
                        <ActionIcon
                            size={52}
                            radius="xl"
                            variant="filled"
                            color="dark"
                            style={{ opacity: 0.85, boxShadow: '0 2px 12px rgba(0,0,0,0.5)' }}
                            onClick={goNext}
                            aria-label={t('Next')}
                        >
                            <LuChevronRight size={28} />
                        </ActionIcon>
                    </Box>
                )}
            </Box>
        </Modal>
    );
};
