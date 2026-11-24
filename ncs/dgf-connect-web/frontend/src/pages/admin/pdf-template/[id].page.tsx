import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuCircle,
    LuEye,
    LuHand,
    LuLock,
    LuMousePointer2,
    LuSave,
    LuScanSearch,
    LuSquare,
    LuZoomIn,
    LuZoomOut,
} from 'react-icons/lu';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import { useRouter } from 'next/router';
import {
    ActionIcon,
    Anchor,
    Box,
    Breadcrumbs,
    Button,
    Card,
    Center,
    Divider,
    Flex,
    Group,
    Loader,
    ScrollArea,
    SegmentedControl,
    Select,
    Text,
    Tooltip,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { useAtom } from 'jotai';
import { ApiConfig } from '@/configs/api.config';
import RouteConfig from '@/configs/route.config';
import { ApiValidationError } from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';
import NotificationHelper from '@/helpers/notification.helper';
import {
    EditorField,
    ToolMode,
    ZOOM_LEVELS,
    ZOOM_MAX,
    ZOOM_MIN,
    clipboardAtom,
    currentPageAtom,
    fieldsAtom,
    isDirtyAtom,
    selectedFieldIdAtom,
    toEditorField,
    toolModeAtom,
    zoomAtom,
} from '@/pages/admin/pdf-template/partials/editor/state';
import {
    FieldValidationErrorMap,
    getInvalidFieldIdsFromApiValidationError,
    validateEditorFields,
} from '@/pages/admin/pdf-template/partials/editor/validation';
import { authAtom } from '@/pages/global.state';
import { usePermissions } from '@/hooks/usePermission';
import {
    useGetDataSourcesQuery,
    useGetPdfTemplateDetailQuery,
    useLockTemplateMutation,
    useSaveFieldsMutation,
    useUnlockTemplateMutation,
} from '@/pages/admin/pdf-template/api';

const PdfRenderer = dynamic(() => import('@/pages/admin/pdf-template/partials/editor/pdf_renderer'), { ssr: false });
const FieldOverlay = dynamic(() => import('@/pages/admin/pdf-template/partials/editor/field_overlay'), { ssr: false });
const FieldConfigPanel = dynamic(() => import('@/pages/admin/pdf-template/partials/editor/field_config_panel'), {
    ssr: false,
});
const PreviewModal = dynamic(() => import('@/pages/admin/pdf-template/partials/preview_modal'), { ssr: false });

const PdfTemplateEditorPage = () => {
    const { t } = useTranslation();
    const router = useRouter();
    const id = router.query.id as string;
    const [auth] = useAtom(authAtom);

    const perms = usePermissions(['PDF_TEMPLATE_LIST', 'PDF_TEMPLATE_UPDATE', 'PDF_TEMPLATE_EXPORT']);
    const { data, isLoading } = useGetPdfTemplateDetailQuery(id, perms.PDF_TEMPLATE_LIST);
    const { data: dataSourcesResponse } = useGetDataSourcesQuery(perms.PDF_TEMPLATE_LIST);
    const template = data?.data;

    const lockMutation = useLockTemplateMutation(id);
    const unlockMutation = useUnlockTemplateMutation(id);
    const saveMutation = useSaveFieldsMutation(id);

    const [fields, setFields] = useAtom(fieldsAtom);
    const [selectedFieldId, setSelectedFieldId] = useAtom(selectedFieldIdAtom);
    const [zoom, setZoom] = useAtom(zoomAtom);
    const [currentPage, setCurrentPage] = useAtom(currentPageAtom);
    const [toolMode, setToolMode] = useAtom(toolModeAtom);
    const [isDirty, setIsDirty] = useAtom(isDirtyAtom);
    const [clipboard, setClipboard] = useAtom(clipboardAtom);

    const [pageDimensions, setPageDimensions] = useState({ width: 0, height: 0 });
    const [invalidFieldIds, setInvalidFieldIds] = useState<string[]>([]);
    const [fieldValidationErrors, setFieldValidationErrors] = useState<FieldValidationErrorMap>({});
    const [previewOpened, { open: openPreview, close: closePreview }] = useDisclosure(false);
    const initializedRef = useRef(false);
    const lockHeldRef = useRef(false);
    const refreshIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const pdfCardRef = useRef<HTMLDivElement>(null);
    const fitToWidthDoneRef = useRef(false);
    const isLockedByOther = template?.lock_info && template.lock_info.user_id !== auth?.user.id;
    const isReadOnly = !!isLockedByOther || !perms.PDF_TEMPLATE_UPDATE;
    const validDataSourceKeys = useMemo(
        () => new Set((dataSourcesResponse?.data ?? []).map((source) => source.key)),
        [dataSourcesResponse],
    );
    const dataSourcePlaceholders = useMemo(
        () => new Map((dataSourcesResponse?.data ?? []).map((source) => [source.key, source.placeholder])),
        [dataSourcesResponse],
    );

    // Initialize fields from template data
    useEffect(() => {
        if (template && !initializedRef.current) {
            setFields(template.fields.map(toEditorField));
            setCurrentPage(1);
            setSelectedFieldId(null);
            setToolMode('select');
            setIsDirty(false);
            setInvalidFieldIds([]);
            setFieldValidationErrors({});
            initializedRef.current = true;
        }
    }, [template]);

    // Reset state on unmount
    useEffect(() => {
        return () => {
            initializedRef.current = false;
            lockHeldRef.current = false;
            fitToWidthDoneRef.current = false;
            if (refreshIntervalRef.current) {
                clearInterval(refreshIntervalRef.current);
                refreshIntervalRef.current = null;
            }
        };
    }, []);

    // Lock management: acquire lock on first change
    const acquireLock = useCallback(async () => {
        if (lockHeldRef.current || isReadOnly || !id) return;
        lockHeldRef.current = true;
        try {
            await lockMutation.mutateAsync({ reason: 'editing' });
        } catch {
            lockHeldRef.current = false;
            NotificationHelper.showError(t('Could not acquire edit lock. The template may be locked by another user.'));
        }
    }, [id, isReadOnly, lockMutation, t]);

    const startRefreshInterval = useCallback(() => {
        if (refreshIntervalRef.current) return;
        refreshIntervalRef.current = setInterval(
            async () => {
                if (!lockHeldRef.current || !id) return;
                try {
                    await lockMutation.mutateAsync({ reason: 'editing' });
                } catch {
                    // Lock may have expired
                }
            },
            5 * 60 * 1000,
        ); // 5 minutes
    }, [id, lockMutation]);

    const stopRefreshInterval = useCallback(() => {
        if (refreshIntervalRef.current) {
            clearInterval(refreshIntervalRef.current);
            refreshIntervalRef.current = null;
        }
    }, []);

    const releaseLock = useCallback(async () => {
        if (!lockHeldRef.current || !id) return;
        stopRefreshInterval();
        try {
            await unlockMutation.mutateAsync();
        } catch {
            // Best effort
        }
        lockHeldRef.current = false;
    }, [id, unlockMutation, stopRefreshInterval]);

    // Mark dirty and acquire lock on first change
    const markDirty = useCallback(() => {
        if (isReadOnly) return;
        setInvalidFieldIds([]);
        setFieldValidationErrors({});
        setIsDirty(true);
        if (!lockHeldRef.current) {
            acquireLock().then(() => {
                if (lockHeldRef.current) startRefreshInterval();
            });
        }
    }, [isReadOnly, setIsDirty, acquireLock, startRefreshInterval]);

    // Release lock on navigation away and beforeunload
    useEffect(() => {
        const handleBeforeUnload = () => {
            if (lockHeldRef.current && id) {
                navigator.sendBeacon?.(
                    `${window.location.origin}/api/admin/pdf-templates/${id}/unlock`,
                    new Blob([], { type: 'application/json' }),
                );
            }
        };

        window.addEventListener('beforeunload', handleBeforeUnload);
        return () => {
            window.removeEventListener('beforeunload', handleBeforeUnload);
        };
    }, [id]);

    // Release lock on route change
    useEffect(() => {
        const handleRouteChange = () => {
            releaseLock();
        };

        router.events.on('routeChangeStart', handleRouteChange);
        return () => {
            router.events.off('routeChangeStart', handleRouteChange);
        };
    }, [router.events, releaseLock]);

    // Keyboard shortcuts: copy (Ctrl+C), paste (Ctrl+V), delete (Delete/Backspace), Space (hold to pan)
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            // Ignore when typing in inputs
            const tag = (e.target as HTMLElement)?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

            const isMeta = e.ctrlKey || e.metaKey;

            // Copy
            if (isMeta && e.key === 'c') {
                const selected = fields.find((f) => f._localId === selectedFieldId);
                if (selected) {
                    setClipboard({ ...selected });
                }
                return;
            }

            // Paste
            if (isMeta && e.key === 'v') {
                if (!clipboard || isReadOnly) return;
                e.preventDefault();
                const pasted: EditorField = {
                    ...clipboard,
                    _localId: `new-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
                    id: 0,
                    page: currentPage,
                    x: Math.min(clipboard.x + 2, 100 - clipboard.width),
                    y: Math.min(clipboard.y + 2, 100 - clipboard.height),
                };
                setFields((prev) => [...prev, pasted]);
                setSelectedFieldId(pasted._localId);
                setClipboard({ ...pasted });
                markDirty();
                return;
            }

            // Delete
            if (e.key === 'Delete' || e.key === 'Backspace') {
                if (!selectedFieldId || isReadOnly) return;
                e.preventDefault();
                setFields((prev) => prev.filter((f) => f._localId !== selectedFieldId));
                setSelectedFieldId(null);
                markDirty();
                return;
            }

            // Move selected field
            if (e.key === 'ArrowUp' || e.key === 'ArrowDown' || e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                if (!selectedFieldId || isReadOnly || pageDimensions.width === 0 || pageDimensions.height === 0) return;

                const selected = fields.find((f) => f._localId === selectedFieldId);
                if (!selected) return;

                e.preventDefault();

                const stepX = 100 / pageDimensions.width;
                const stepY = 100 / pageDimensions.height;
                const deltaX = e.key === 'ArrowLeft' ? -stepX : e.key === 'ArrowRight' ? stepX : 0;
                const deltaY = e.key === 'ArrowUp' ? -stepY : e.key === 'ArrowDown' ? stepY : 0;
                const nextX = Math.max(0, Math.min(100 - selected.width, selected.x + deltaX));
                const nextY = Math.max(0, Math.min(100 - selected.height, selected.y + deltaY));

                if (nextX === selected.x && nextY === selected.y) return;

                setFields((prev) =>
                    prev.map((field) =>
                        field._localId === selectedFieldId ? { ...field, x: nextX, y: nextY } : field,
                    ),
                );
                markDirty();
            }
        };

        window.addEventListener('keydown', handleKeyDown);
        return () => {
            window.removeEventListener('keydown', handleKeyDown);
        };
    }, [
        fields,
        selectedFieldId,
        clipboard,
        currentPage,
        isReadOnly,
        pageDimensions,
        setFields,
        setSelectedFieldId,
        setClipboard,
        markDirty,
    ]);

    // Save handler
    const handleSave = async () => {
        if (!id || isReadOnly) return;

        const { invalidFieldIds: nextInvalidFieldIds, fieldErrorsById } = validateEditorFields(
            fields,
            validDataSourceKeys,
        );
        if (nextInvalidFieldIds.length > 0) {
            setInvalidFieldIds(nextInvalidFieldIds);
            setFieldValidationErrors(fieldErrorsById);

            const firstInvalidField = fields.find((field) => field._localId === nextInvalidFieldIds[0]);
            if (firstInvalidField) {
                setCurrentPage(firstInvalidField.page);
                setSelectedFieldId(firstInvalidField._localId);
            }

            NotificationHelper.showError(t('Please fix the highlighted fields before saving'));
            return;
        }

        try {
            const payload = fields.map(({ _localId, ...field }) => field);
            const response = await saveMutation.mutateAsync({ fields: payload });
            if (response?.data?.fields) {
                setFields(response.data.fields.map(toEditorField));
            }
            await releaseLock();
            setIsDirty(false);
            setInvalidFieldIds([]);
            setFieldValidationErrors({});
            NotificationHelper.showSuccess(t('Fields saved successfully'));
        } catch (error) {
            if (error instanceof ApiValidationError) {
                const { invalidFieldIds: serverInvalidFieldIds, fieldErrorsById: serverFieldErrorsById } =
                    getInvalidFieldIdsFromApiValidationError(error, fields);
                if (serverInvalidFieldIds.length > 0) {
                    setInvalidFieldIds(serverInvalidFieldIds);
                    setFieldValidationErrors(serverFieldErrorsById);

                    const firstInvalidField = fields.find((field) => field._localId === serverInvalidFieldIds[0]);
                    if (firstInvalidField) {
                        setCurrentPage(firstInvalidField.page);
                        setSelectedFieldId(firstInvalidField._localId);
                    }
                }

                NotificationHelper.showError(t('Please fix the highlighted fields before saving'));
                return;
            }

            NotificationHelper.showError(t('Failed to save fields'));
        }
    };

    // Zoom handlers — step through predefined levels
    const handleZoomIn = () => setZoom((z) => ZOOM_LEVELS.find((l) => l > z) ?? ZOOM_MAX);
    const handleZoomOut = () => setZoom((z) => [...ZOOM_LEVELS].reverse().find((l) => l < z) ?? ZOOM_MIN);

    const handleFitToWidth = useCallback(() => {
        const card = pdfCardRef.current;
        if (!card || pageDimensions.width === 0) return;
        const availableWidth = card.clientWidth - 32;
        if (availableWidth <= 0) return;
        const raw = availableWidth / pageDimensions.width;
        const clamped = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, raw));
        setZoom(clamped);
    }, [pageDimensions.width, setZoom]);

    // Page navigation
    const pageCount = template?.page_count ?? 1;
    const pageOptions = Array.from({ length: pageCount }, (_, i) => ({
        value: String(i + 1),
        label: `${i + 1} / ${pageCount}`,
    }));

    const handlePageDimensions = useCallback((width: number, height: number) => {
        setPageDimensions({ width, height });
    }, []);

    // Fit-to-width on first render
    useEffect(() => {
        if (fitToWidthDoneRef.current || pageDimensions.width === 0) return;
        if (!pdfCardRef.current) return;
        handleFitToWidth();
        fitToWidthDoneRef.current = true;
    }, [pageDimensions.width, handleFitToWidth]);

    if (isLoading) {
        return (
            <Center h={400}>
                <Loader />
            </Center>
        );
    }

    if (!template) {
        return (
            <Center h={400}>
                <Text>{t('Template not found')}</Text>
            </Center>
        );
    }

    const lockInfo = template.lock_info;
    const lockReasonLabel = lockInfo ? t(lockInfo.reason) : '';

    const readOnlyTooltip = isLockedByOther
        ? t('This template is locked by {{name}} ({{reason}}). You can view but not edit.', {
              name: lockInfo?.user_name ?? '',
              reason: lockReasonLabel,
          })
        : t('You do not have permission to edit this template.');

    const toolModeOptions = [
        {
            value: 'select',
            label: (
                <Tooltip label={t('Select')}>
                    <Center>
                        <LuMousePointer2 size={16} />
                    </Center>
                </Tooltip>
            ),
        },
        {
            value: 'pan',
            label: (
                <Tooltip label={t('Pan')}>
                    <Center>
                        <LuHand size={16} />
                    </Center>
                </Tooltip>
            ),
        },
        ...(!isReadOnly
            ? [
                  {
                      value: 'draw-rect',
                      label: (
                          <Tooltip label={t('Draw Rectangle')}>
                              <Center>
                                  <LuSquare size={16} />
                              </Center>
                          </Tooltip>
                      ),
                  },
                  {
                      value: 'draw-circle',
                      label: (
                          <Tooltip label={t('Draw Circle')}>
                              <Center>
                                  <LuCircle size={16} />
                              </Center>
                          </Tooltip>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <Box h="calc(100vh - 90px)" style={{ display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
            {/* Breadcrumbs */}
            <Box px="md" pt="xs" pb="xs">
                <Breadcrumbs>
                    <Anchor component={Link} href={RouteConfig.HOME.path}>
                        {t('Home')}
                    </Anchor>
                    <Anchor component={Link} href={RouteConfig.ADMIN_PDF_TEMPLATE_LIST.path}>
                        {t('PDF Templates')}
                    </Anchor>
                    <Text>{template.name}</Text>
                </Breadcrumbs>
            </Box>

            {/* Toolbar */}
            <Card px="md" py="xs">
                <Flex justify="space-between" align="center">
                    <Group gap="sm">
                        {isReadOnly && (
                            <Tooltip label={readOnlyTooltip} multiline maw={300}>
                                <Group gap={6} style={{ cursor: 'default' }}>
                                    <LuLock size={14} />
                                    <Text size="xs" c="dimmed">
                                        {t('Read only')}
                                    </Text>
                                </Group>
                            </Tooltip>
                        )}
                    </Group>

                    <Group gap="sm">
                        {/* Tool mode */}
                        <SegmentedControl
                            size="xs"
                            value={toolMode}
                            onChange={(value) => setToolMode(value as ToolMode)}
                            data={toolModeOptions}
                        />

                        {/* Page selector */}
                        <Select
                            size="xs"
                            w={100}
                            value={String(currentPage)}
                            onChange={(val) => val && setCurrentPage(Number(val))}
                            data={pageOptions}
                            allowDeselect={false}
                        />

                        {/* Zoom controls */}
                        <Group gap={4}>
                            <Tooltip label={t('Zoom out')}>
                                <ActionIcon
                                    variant="subtle"
                                    size="sm"
                                    onClick={handleZoomOut}
                                    disabled={zoom <= ZOOM_MIN}
                                >
                                    <LuZoomOut size={16} />
                                </ActionIcon>
                            </Tooltip>
                            <Text size="xs" w={50} ta="center">
                                {Math.round(zoom * 100)}%
                            </Text>
                            <Tooltip label={t('Zoom in')}>
                                <ActionIcon
                                    variant="subtle"
                                    size="sm"
                                    onClick={handleZoomIn}
                                    disabled={zoom >= ZOOM_MAX}
                                >
                                    <LuZoomIn size={16} />
                                </ActionIcon>
                            </Tooltip>
                            <Tooltip label={t('Fit to width')}>
                                <ActionIcon variant="subtle" size="sm" onClick={handleFitToWidth}>
                                    <LuScanSearch size={16} />
                                </ActionIcon>
                            </Tooltip>
                        </Group>

                        <Divider orientation="vertical" h={24} />

                        {/* Preview button */}
                        <Button
                            size="xs"
                            leftSection={<LuEye size={14} />}
                            disabled={!perms.PDF_TEMPLATE_EXPORT}
                            onClick={openPreview}
                        >
                            {t('Preview')}
                        </Button>

                        {/* Save button */}
                        {!isReadOnly && (
                            <Button
                                size="xs"
                                leftSection={<LuSave size={14} />}
                                onClick={handleSave}
                                loading={saveMutation.isPending}
                                disabled={!isDirty}
                            >
                                {t('Save')}
                            </Button>
                        )}
                    </Group>
                </Flex>
            </Card>

            {/* Main editor area */}
            <Flex mt="md" pb="md" flex="1" gap="md" style={{ overflow: 'hidden' }}>
                {/* PDF canvas area */}
                <Card ref={pdfCardRef} flex="1" p={0} style={{ overflow: 'hidden' }}>
                    <ScrollArea h="100%" p="md">
                        <Box style={{ width: 'fit-content', margin: '0 auto' }}>
                            <Box pos="relative" style={{ display: 'inline-block' }}>
                                <PdfRenderer
                                    fileUrl={`${ApiConfig.BASE_URL}/${CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PDF_TEMPLATE_FILE, { id })}`}
                                    currentPage={currentPage}
                                    zoom={zoom}
                                    onPageDimensions={handlePageDimensions}
                                />
                                <FieldOverlay
                                    pageDimensions={pageDimensions}
                                    invalidFieldIds={invalidFieldIds}
                                    dataSourcePlaceholders={dataSourcePlaceholders}
                                    onMarkDirty={markDirty}
                                />
                            </Box>
                        </Box>
                    </ScrollArea>
                </Card>

                {/* Field config panel */}
                <Card w={300} miw={300} p={0} style={{ overflow: 'hidden' }}>
                    <FieldConfigPanel
                        hasPermission={perms.PDF_TEMPLATE_LIST}
                        isReadOnly={isReadOnly}
                        onMarkDirty={markDirty}
                        validationErrors={selectedFieldId ? (fieldValidationErrors[selectedFieldId] ?? {}) : {}}
                    />
                </Card>
            </Flex>

            <PreviewModal opened={previewOpened} templateId={id} templateName={template.name} onClose={closePreview} />
        </Box>
    );
};

export default PdfTemplateEditorPage;
