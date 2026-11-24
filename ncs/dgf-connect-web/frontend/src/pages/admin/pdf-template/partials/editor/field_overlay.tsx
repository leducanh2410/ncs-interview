import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useAtom, useAtomValue } from 'jotai';
import i18n from '@/locales/i18n';
import {
    EditorField,
    ZOOM_LEVELS,
    ZOOM_MAX,
    ZOOM_MIN,
    currentPageAtom,
    fieldsAtom,
    newEditorField,
    selectedFieldIdAtom,
    toolModeAtom,
    zoomAtom,
} from './state';

const HANDLE_SIZE = 8;
const HANDLE_HIT_SIZE = HANDLE_SIZE + 4; // larger hit area for easier targeting
const FIELD_FILL_COLOR = 'rgba(59, 130, 246, 0.15)';
const FIELD_STROKE_COLOR = 'rgba(59, 130, 246, 0.6)';
const SELECTED_FILL_COLOR = 'rgba(59, 130, 246, 0.25)';
const SELECTED_STROKE_COLOR = 'rgba(37, 99, 235, 1)';
const INVALID_FIELD_FILL_COLOR = 'rgba(239, 68, 68, 0.18)';
const INVALID_FIELD_STROKE_COLOR = 'rgba(220, 38, 38, 0.7)';
const INVALID_SELECTED_FILL_COLOR = 'rgba(239, 68, 68, 0.28)';
const INVALID_SELECTED_STROKE_COLOR = 'rgba(185, 28, 28, 1)';
const HANDLE_FILL = '#ffffff';
const HANDLE_STROKE = 'rgba(37, 99, 235, 1)';
const LABEL_FONT_SIZE = 11;
const MIN_LABEL_WIDTH = 30;
const MIN_FIELD_PCT = 0.25; // keep fields resizable while allowing much smaller shapes
const MIN_DRAW_PX = 1;
const PREVIEW_FILL = 'rgba(59, 130, 246, 0.2)';
const PREVIEW_STROKE = 'rgba(37, 99, 235, 0.8)';
const DRAG_THRESHOLD = 3; // pixels — movement below this is treated as a click
const t = i18n.t.bind(i18n);

type FieldOverlayProps = {
    pageDimensions: { width: number; height: number };
    invalidFieldIds: string[];
    dataSourcePlaceholders: Map<string, string>;
    onMarkDirty: () => void;
};

type PreviewRect = { x: number; y: number; w: number; h: number; shape: 'rectangle' | 'circle' };

type HandleType = 'nw' | 'ne' | 'se' | 'sw' | 'n' | 'e' | 's' | 'w';

type InteractionState =
    | { type: 'pan'; startClientX: number; startClientY: number; startScrollLeft: number; startScrollTop: number }
    | { type: 'draw'; startX: number; startY: number }
    | {
          type: 'drag';
          fieldId: string;
          startMouseX: number;
          startMouseY: number;
          initX: number;
          initY: number;
          fieldW: number;
          fieldH: number;
      }
    | {
          type: 'resize';
          fieldId: string;
          handleType: HandleType;
          startMouseX: number;
          startMouseY: number;
          initX: number;
          initY: number;
          initW: number;
          initH: number;
      };

/** Find the nearest scrollable ancestor element. */
const findScrollableParent = (el: HTMLElement | null): HTMLElement | null => {
    let node = el?.parentElement ?? null;
    while (node) {
        const { overflow, overflowX, overflowY } = getComputedStyle(node);
        if (/(auto|scroll)/.test(overflow + overflowX + overflowY)) return node;
        node = node.parentElement;
    }
    return null;
};

/** Convert field percentage coordinates to canvas pixel coordinates. */
const fieldToPixels = (field: EditorField, pageWidth: number, pageHeight: number, zoom: number) => ({
    x: (field.x / 100) * pageWidth * zoom,
    y: (field.y / 100) * pageHeight * zoom,
    w: (field.width / 100) * pageWidth * zoom,
    h: (field.height / 100) * pageHeight * zoom,
});

const clamp = (val: number, min: number, max: number) => Math.max(min, Math.min(max, val));

const normalizeRect = (startX: number, startY: number, endX: number, endY: number) => ({
    x: Math.min(startX, endX),
    y: Math.min(startY, endY),
    w: Math.abs(endX - startX),
    h: Math.abs(endY - startY),
});

const getConstrainedDrawRect = (
    startX: number,
    startY: number,
    endX: number,
    endY: number,
    constrainProportions: boolean,
    maxWidth: number,
    maxHeight: number,
) => {
    if (!constrainProportions) {
        return normalizeRect(startX, startY, endX, endY);
    }

    const rawDx = endX - startX;
    const rawDy = endY - startY;
    const fallbackDirection = rawDx !== 0 ? Math.sign(rawDx) : rawDy !== 0 ? Math.sign(rawDy) : 1;
    const dirX = rawDx === 0 ? fallbackDirection : Math.sign(rawDx);
    const dirY = rawDy === 0 ? fallbackDirection : Math.sign(rawDy);
    const targetSize = Math.max(Math.abs(rawDx), Math.abs(rawDy));
    const availableX = dirX >= 0 ? maxWidth - startX : startX;
    const availableY = dirY >= 0 ? maxHeight - startY : startY;
    const size = Math.min(targetSize, availableX, availableY);

    return normalizeRect(startX, startY, startX + dirX * size, startY + dirY * size);
};

/** Return 8 resize handle centre-points for a pixel-space rectangle. */
export const getHandlePositions = (x: number, y: number, w: number, h: number) => [
    { cx: x, cy: y, cursor: 'nwse-resize' as const, type: 'nw' as const },
    { cx: x + w, cy: y, cursor: 'nesw-resize' as const, type: 'ne' as const },
    { cx: x + w, cy: y + h, cursor: 'nwse-resize' as const, type: 'se' as const },
    { cx: x, cy: y + h, cursor: 'nesw-resize' as const, type: 'sw' as const },
    { cx: x + w / 2, cy: y, cursor: 'ns-resize' as const, type: 'n' as const },
    { cx: x + w, cy: y + h / 2, cursor: 'ew-resize' as const, type: 'e' as const },
    { cx: x + w / 2, cy: y + h, cursor: 'ns-resize' as const, type: 's' as const },
    { cx: x, cy: y + h / 2, cursor: 'ew-resize' as const, type: 'w' as const },
];

// ---------------------------------------------------------------------------
// Canvas drawing helpers
// ---------------------------------------------------------------------------

const drawField = (
    ctx: CanvasRenderingContext2D,
    field: EditorField,
    px: { x: number; y: number; w: number; h: number },
    isSelected: boolean,
    isInvalid: boolean,
) => {
    ctx.fillStyle = isInvalid
        ? isSelected
            ? INVALID_SELECTED_FILL_COLOR
            : INVALID_FIELD_FILL_COLOR
        : isSelected
          ? SELECTED_FILL_COLOR
          : FIELD_FILL_COLOR;
    ctx.strokeStyle = isInvalid
        ? isSelected
            ? INVALID_SELECTED_STROKE_COLOR
            : INVALID_FIELD_STROKE_COLOR
        : isSelected
          ? SELECTED_STROKE_COLOR
          : FIELD_STROKE_COLOR;
    ctx.lineWidth = isSelected ? 2 : 1;

    if (field.shape === 'circle') {
        ctx.beginPath();
        ctx.ellipse(px.x + px.w / 2, px.y + px.h / 2, px.w / 2, px.h / 2, 0, 0, Math.PI * 2);
        ctx.fill();
        ctx.stroke();
    } else {
        ctx.fillRect(px.x, px.y, px.w, px.h);
        ctx.strokeRect(px.x, px.y, px.w, px.h);
    }
};

const drawLabel = (
    ctx: CanvasRenderingContext2D,
    field: EditorField,
    px: { x: number; y: number; w: number; h: number },
    zoom: number,
    dataSourcePlaceholders: Map<string, string>,
) => {
    const dsPlaceholder = field.data_source_key ? dataSourcePlaceholders.get(field.data_source_key) : undefined;
    const label = field.placeholder || dsPlaceholder || field.data_source_key || `(${t('No source')})`;
    const tc = field.text_config;
    const fontSize = tc ? tc.font_size * zoom : LABEL_FONT_SIZE;
    const fontWeight = tc?.font_weight === 'bold' ? 'bold' : 'normal';
    const fontStyle = tc?.font_style === 'italic' ? 'italic' : 'normal';

    if (px.w < MIN_LABEL_WIDTH || px.h < fontSize + 2) return;

    ctx.save();
    ctx.font = `${fontStyle} ${fontWeight} ${fontSize}px sans-serif`;
    ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';

    const pad = 4;

    // Vertical alignment
    let textY: number;
    if (tc?.v_align === 'middle') {
        ctx.textBaseline = 'middle';
        textY = px.y + px.h / 2;
    } else if (tc?.v_align === 'bottom') {
        ctx.textBaseline = 'bottom';
        textY = px.y + px.h - pad;
    } else {
        ctx.textBaseline = 'top';
        textY = px.y + pad;
    }

    ctx.beginPath();
    ctx.rect(px.x + 2, px.y + 2, px.w - 4, px.h - 4);
    ctx.clip();

    // Auto letter spacing: distribute characters evenly across field width
    if (tc?.letter_spacing === 'auto') {
        const chars = [...label];
        if (chars.length <= 1) {
            ctx.textAlign = 'center';
            ctx.fillText(label, px.x + px.w / 2, textY);
        } else {
            ctx.textAlign = 'left';
            let totalCharWidth = 0;
            const charWidths = chars.map((c) => {
                const w = ctx.measureText(c).width;
                totalCharWidth += w;
                return w;
            });
            const spacing = (px.w - totalCharWidth) / (chars.length - 1);
            let currentX = px.x;
            for (let i = 0; i < chars.length; i++) {
                ctx.fillText(chars[i], currentX, textY);
                currentX += charWidths[i] + spacing;
            }
        }
    } else {
        // Normal rendering with horizontal alignment
        let textX = px.x + pad;
        if (tc?.h_align === 'center') {
            ctx.textAlign = 'center';
            textX = px.x + px.w / 2;
        } else if (tc?.h_align === 'right') {
            ctx.textAlign = 'right';
            textX = px.x + px.w - pad;
        } else {
            ctx.textAlign = 'left';
        }
        ctx.fillText(label, textX, textY);
    }

    ctx.restore();
};

const drawHandles = (ctx: CanvasRenderingContext2D, px: { x: number; y: number; w: number; h: number }) => {
    const half = HANDLE_SIZE / 2;
    for (const h of getHandlePositions(px.x, px.y, px.w, px.h)) {
        ctx.fillStyle = HANDLE_FILL;
        ctx.strokeStyle = HANDLE_STROKE;
        ctx.lineWidth = 1.5;
        ctx.fillRect(h.cx - half, h.cy - half, HANDLE_SIZE, HANDLE_SIZE);
        ctx.strokeRect(h.cx - half, h.cy - half, HANDLE_SIZE, HANDLE_SIZE);
    }
};

const drawPreview = (ctx: CanvasRenderingContext2D, preview: PreviewRect) => {
    ctx.fillStyle = PREVIEW_FILL;
    ctx.strokeStyle = PREVIEW_STROKE;
    ctx.lineWidth = 1.5;
    ctx.setLineDash([5, 3]);

    if (preview.shape === 'circle') {
        ctx.beginPath();
        ctx.ellipse(
            preview.x + preview.w / 2,
            preview.y + preview.h / 2,
            preview.w / 2,
            preview.h / 2,
            0,
            0,
            Math.PI * 2,
        );
        ctx.fill();
        ctx.stroke();
    } else {
        ctx.fillRect(preview.x, preview.y, preview.w, preview.h);
        ctx.strokeRect(preview.x, preview.y, preview.w, preview.h);
    }

    ctx.setLineDash([]);
};

// ---------------------------------------------------------------------------
// Hit testing
// ---------------------------------------------------------------------------

const hitTestField = (
    field: EditorField,
    mouseX: number,
    mouseY: number,
    px: { x: number; y: number; w: number; h: number },
) => {
    if (field.shape === 'circle') {
        const cx = px.x + px.w / 2;
        const cy = px.y + px.h / 2;
        const rx = px.w / 2;
        const ry = px.h / 2;
        if (rx === 0 || ry === 0) return false;
        const dx = (mouseX - cx) / rx;
        const dy = (mouseY - cy) / ry;
        return dx * dx + dy * dy <= 1;
    }
    return mouseX >= px.x && mouseX <= px.x + px.w && mouseY >= px.y && mouseY <= px.y + px.h;
};

/** Hit-test against the 8 resize handles of a field. Returns the handle info or null. */
const hitTestHandle = (
    mouseX: number,
    mouseY: number,
    px: { x: number; y: number; w: number; h: number },
): { type: HandleType; cursor: string } | null => {
    const half = HANDLE_HIT_SIZE / 2;
    for (const h of getHandlePositions(px.x, px.y, px.w, px.h)) {
        if (mouseX >= h.cx - half && mouseX <= h.cx + half && mouseY >= h.cy - half && mouseY <= h.cy + half) {
            return { type: h.type, cursor: h.cursor };
        }
    }
    return null;
};

// ---------------------------------------------------------------------------
// Component
// ---------------------------------------------------------------------------

const FieldOverlay = ({ pageDimensions, invalidFieldIds, dataSourcePlaceholders, onMarkDirty }: FieldOverlayProps) => {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const interactionRef = useRef<InteractionState | null>(null);

    const [fields, setFields] = useAtom(fieldsAtom);
    const [selectedFieldId, setSelectedFieldId] = useAtom(selectedFieldIdAtom);
    const currentPage = useAtomValue(currentPageAtom);
    const [zoom, setZoom] = useAtom(zoomAtom);
    const [toolMode, setToolMode] = useAtom(toolModeAtom);

    const [cursorStyle, setCursorStyle] = useState<string>('default');

    const canvasWidth = Math.floor(pageDimensions.width * zoom);
    const canvasHeight = Math.floor(pageDimensions.height * zoom);

    const currentPageFields = useMemo(() => fields.filter((f) => f.page === currentPage), [fields, currentPage]);
    const invalidFieldIdSet = useMemo(() => new Set(invalidFieldIds), [invalidFieldIds]);

    // ------ Helper: mouse position relative to canvas, clamped to bounds ------
    const getMousePos = useCallback(
        (e: MouseEvent | React.MouseEvent) => {
            const canvas = canvasRef.current;
            if (!canvas) return { x: 0, y: 0 };
            const rect = canvas.getBoundingClientRect();
            return {
                x: clamp(e.clientX - rect.left, 0, canvasWidth),
                y: clamp(e.clientY - rect.top, 0, canvasHeight),
            };
        },
        [canvasWidth, canvasHeight],
    );

    // ------ Repaint canvas: all fields + optional preview shape ------
    const repaint = useCallback(
        (preview?: PreviewRect) => {
            const canvas = canvasRef.current;
            if (!canvas || canvasWidth <= 0 || canvasHeight <= 0) return;

            const ctx = canvas.getContext('2d');
            if (!ctx) return;

            canvas.width = canvasWidth;
            canvas.height = canvasHeight;
            ctx.clearRect(0, 0, canvasWidth, canvasHeight);

            for (const field of currentPageFields) {
                const px = fieldToPixels(field, pageDimensions.width, pageDimensions.height, zoom);
                const isSelected = field._localId === selectedFieldId;
                const isInvalid = invalidFieldIdSet.has(field._localId);

                drawField(ctx, field, px, isSelected, isInvalid);
                drawLabel(ctx, field, px, zoom, dataSourcePlaceholders);

                if (isSelected) {
                    drawHandles(ctx, px);
                }
            }

            if (preview) {
                drawPreview(ctx, preview);
            }
        },
        [
            currentPageFields,
            selectedFieldId,
            invalidFieldIdSet,
            canvasWidth,
            canvasHeight,
            pageDimensions,
            zoom,
            dataSourcePlaceholders,
        ],
    );

    // ------ Normal render (no drag in progress) ------
    useEffect(() => {
        repaint();
    }, [repaint]);

    // ------ Mouse handlers ------
    const handleMouseDown = useCallback(
        (e: React.MouseEvent<HTMLCanvasElement>) => {
            const pos = getMousePos(e);

            // --- Middle mouse button pan (works in any tool mode) ---
            if (e.button === 1) {
                e.preventDefault();
                const viewport = findScrollableParent(canvasRef.current);
                if (!viewport) return;
                interactionRef.current = {
                    type: 'pan',
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    startScrollLeft: viewport.scrollLeft,
                    startScrollTop: viewport.scrollTop,
                };
                const prevCursor = cursorStyle;
                setCursorStyle('grabbing');

                const onMove = (me: MouseEvent) => {
                    const state = interactionRef.current;
                    if (!state || state.type !== 'pan') return;
                    viewport.scrollLeft = state.startScrollLeft - (me.clientX - state.startClientX);
                    viewport.scrollTop = state.startScrollTop - (me.clientY - state.startClientY);
                };

                const onUp = () => {
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    interactionRef.current = null;
                    setCursorStyle(prevCursor);
                };

                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
                return;
            }

            // --- Pan mode ---
            if (toolMode === 'pan') {
                e.preventDefault();
                const viewport = findScrollableParent(canvasRef.current);
                if (!viewport) return;
                interactionRef.current = {
                    type: 'pan',
                    startClientX: e.clientX,
                    startClientY: e.clientY,
                    startScrollLeft: viewport.scrollLeft,
                    startScrollTop: viewport.scrollTop,
                };
                setCursorStyle('grabbing');

                const onMove = (me: MouseEvent) => {
                    const state = interactionRef.current;
                    if (!state || state.type !== 'pan') return;
                    viewport.scrollLeft = state.startScrollLeft - (me.clientX - state.startClientX);
                    viewport.scrollTop = state.startScrollTop - (me.clientY - state.startClientY);
                };

                const onUp = () => {
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    interactionRef.current = null;
                    setCursorStyle('grab');
                };

                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
                return;
            }

            // --- Draw mode (draw-rect / draw-circle) ---
            if (toolMode === 'draw-rect' || toolMode === 'draw-circle') {
                const drawShape = toolMode === 'draw-circle' ? ('circle' as const) : ('rectangle' as const);
                interactionRef.current = { type: 'draw', startX: pos.x, startY: pos.y };

                const onMove = (me: MouseEvent) => {
                    const state = interactionRef.current;
                    if (!state || state.type !== 'draw') return;
                    const cur = getMousePos(me);
                    const rect = getConstrainedDrawRect(
                        state.startX,
                        state.startY,
                        cur.x,
                        cur.y,
                        me.shiftKey,
                        canvasWidth,
                        canvasHeight,
                    );
                    repaint({ ...rect, shape: drawShape });
                };

                const onUp = (me: MouseEvent) => {
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);

                    const state = interactionRef.current;
                    if (!state || state.type !== 'draw') return;
                    interactionRef.current = null;

                    const cur = getMousePos(me);
                    const rect = getConstrainedDrawRect(
                        state.startX,
                        state.startY,
                        cur.x,
                        cur.y,
                        me.shiftKey,
                        canvasWidth,
                        canvasHeight,
                    );

                    if (rect.w < MIN_DRAW_PX || rect.h < MIN_DRAW_PX) {
                        repaint();
                        return;
                    }

                    const pctX = (rect.x / (pageDimensions.width * zoom)) * 100;
                    const pctY = (rect.y / (pageDimensions.height * zoom)) * 100;
                    const pctW = (rect.w / (pageDimensions.width * zoom)) * 100;
                    const pctH = (rect.h / (pageDimensions.height * zoom)) * 100;

                    const field = newEditorField(currentPage, drawShape, pctX, pctY, pctW, pctH);
                    setFields((prev) => [...prev, field]);
                    setSelectedFieldId(field._localId);
                    setToolMode('select');
                    onMarkDirty();
                };

                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
                return;
            }

            // --- Select mode ---

            // 1. Check resize handles of the currently selected field
            if (selectedFieldId) {
                const selectedField = currentPageFields.find((f) => f._localId === selectedFieldId);
                if (selectedField) {
                    const px = fieldToPixels(selectedField, pageDimensions.width, pageDimensions.height, zoom);
                    const handle = hitTestHandle(pos.x, pos.y, px);
                    if (handle) {
                        e.preventDefault();
                        interactionRef.current = {
                            type: 'resize',
                            fieldId: selectedField._localId,
                            handleType: handle.type,
                            startMouseX: pos.x,
                            startMouseY: pos.y,
                            initX: selectedField.x,
                            initY: selectedField.y,
                            initW: selectedField.width,
                            initH: selectedField.height,
                        };
                        setCursorStyle(handle.cursor);

                        const onMove = (me: MouseEvent) => {
                            me.preventDefault();
                            const state = interactionRef.current;
                            if (!state || state.type !== 'resize') return;
                            const cur = getMousePos(me);
                            const deltaPctX = ((cur.x - state.startMouseX) / (pageDimensions.width * zoom)) * 100;
                            const deltaPctY = ((cur.y - state.startMouseY) / (pageDimensions.height * zoom)) * 100;

                            let { initX: x, initY: y, initW: w, initH: h } = state;
                            const ht = state.handleType;

                            const movesLeft = ht === 'nw' || ht === 'w' || ht === 'sw';
                            const movesRight = ht === 'ne' || ht === 'e' || ht === 'se';
                            const movesTop = ht === 'nw' || ht === 'n' || ht === 'ne';
                            const movesBottom = ht === 'sw' || ht === 's' || ht === 'se';

                            if (movesLeft) {
                                x += deltaPctX;
                                w -= deltaPctX;
                            }
                            if (movesRight) {
                                w += deltaPctX;
                            }
                            if (movesTop) {
                                y += deltaPctY;
                                h -= deltaPctY;
                            }
                            if (movesBottom) {
                                h += deltaPctY;
                            }

                            // Enforce minimum size (keep opposite edge fixed)
                            if (w < MIN_FIELD_PCT) {
                                w = MIN_FIELD_PCT;
                                if (movesLeft) x = state.initX + state.initW - MIN_FIELD_PCT;
                            }
                            if (h < MIN_FIELD_PCT) {
                                h = MIN_FIELD_PCT;
                                if (movesTop) y = state.initY + state.initH - MIN_FIELD_PCT;
                            }

                            // Clamp to page bounds
                            x = clamp(x, 0, 100 - w);
                            y = clamp(y, 0, 100 - h);
                            w = clamp(w, MIN_FIELD_PCT, 100 - x);
                            h = clamp(h, MIN_FIELD_PCT, 100 - y);

                            setFields((prev) =>
                                prev.map((f) =>
                                    f._localId === state.fieldId ? { ...f, x, y, width: w, height: h } : f,
                                ),
                            );
                        };

                        const onUp = (me: MouseEvent) => {
                            window.removeEventListener('mousemove', onMove);
                            window.removeEventListener('mouseup', onUp);
                            const state = interactionRef.current;
                            interactionRef.current = null;
                            setCursorStyle('default');
                            if (!state || state.type !== 'resize') return;

                            const cur = getMousePos(me);
                            const moved =
                                Math.abs(cur.x - state.startMouseX) > DRAG_THRESHOLD ||
                                Math.abs(cur.y - state.startMouseY) > DRAG_THRESHOLD;
                            if (moved) onMarkDirty();
                        };

                        window.addEventListener('mousemove', onMove);
                        window.addEventListener('mouseup', onUp);
                        return;
                    }
                }
            }

            // 2. Hit-test all fields (topmost first)
            let hitField: EditorField | null = null;
            for (let i = currentPageFields.length - 1; i >= 0; i--) {
                const field = currentPageFields[i];
                const px = fieldToPixels(field, pageDimensions.width, pageDimensions.height, zoom);
                if (hitTestField(field, pos.x, pos.y, px)) {
                    hitField = field;
                    break;
                }
            }

            if (hitField) {
                setSelectedFieldId(hitField._localId);

                // Start drag immediately so user can click-and-drag in one motion
                e.preventDefault();
                interactionRef.current = {
                    type: 'drag',
                    fieldId: hitField._localId,
                    startMouseX: pos.x,
                    startMouseY: pos.y,
                    initX: hitField.x,
                    initY: hitField.y,
                    fieldW: hitField.width,
                    fieldH: hitField.height,
                };
                setCursorStyle('grabbing');

                const onMove = (me: MouseEvent) => {
                    me.preventDefault();
                    const state = interactionRef.current;
                    if (!state || state.type !== 'drag') return;
                    const cur = getMousePos(me);
                    const deltaPctX = ((cur.x - state.startMouseX) / (pageDimensions.width * zoom)) * 100;
                    const deltaPctY = ((cur.y - state.startMouseY) / (pageDimensions.height * zoom)) * 100;
                    const newX = clamp(state.initX + deltaPctX, 0, 100 - state.fieldW);
                    const newY = clamp(state.initY + deltaPctY, 0, 100 - state.fieldH);

                    setFields((prev) =>
                        prev.map((f) => (f._localId === state.fieldId ? { ...f, x: newX, y: newY } : f)),
                    );
                };

                const onUp = (me: MouseEvent) => {
                    window.removeEventListener('mousemove', onMove);
                    window.removeEventListener('mouseup', onUp);
                    const state = interactionRef.current;
                    interactionRef.current = null;
                    setCursorStyle('default');
                    if (!state || state.type !== 'drag') return;

                    const cur = getMousePos(me);
                    const moved =
                        Math.abs(cur.x - state.startMouseX) > DRAG_THRESHOLD ||
                        Math.abs(cur.y - state.startMouseY) > DRAG_THRESHOLD;
                    if (moved) onMarkDirty();
                };

                window.addEventListener('mousemove', onMove);
                window.addEventListener('mouseup', onUp);
            } else {
                // Clicked empty area — deselect
                setSelectedFieldId(null);
            }
        },
        [
            toolMode,
            cursorStyle,
            selectedFieldId,
            currentPageFields,
            currentPage,
            pageDimensions,
            zoom,
            canvasWidth,
            canvasHeight,
            getMousePos,
            repaint,
            setFields,
            setSelectedFieldId,
            setToolMode,
            onMarkDirty,
        ],
    );

    // ------ Hover cursor handling ------
    const handleMouseMove = useCallback(
        (e: React.MouseEvent<HTMLCanvasElement>) => {
            if (toolMode === 'pan') {
                if (!interactionRef.current) setCursorStyle('grab');
                return;
            }
            if (toolMode !== 'select' || interactionRef.current) return;

            const pos = getMousePos(e);

            // Check resize handles of the selected field
            if (selectedFieldId) {
                const selectedField = currentPageFields.find((f) => f._localId === selectedFieldId);
                if (selectedField) {
                    const px = fieldToPixels(selectedField, pageDimensions.width, pageDimensions.height, zoom);
                    const handle = hitTestHandle(pos.x, pos.y, px);
                    if (handle) {
                        setCursorStyle(handle.cursor);
                        return;
                    }
                    if (hitTestField(selectedField, pos.x, pos.y, px)) {
                        setCursorStyle('grab');
                        return;
                    }
                }
            }

            // Check if hovering over any field
            for (let i = currentPageFields.length - 1; i >= 0; i--) {
                const field = currentPageFields[i];
                const px = fieldToPixels(field, pageDimensions.width, pageDimensions.height, zoom);
                if (hitTestField(field, pos.x, pos.y, px)) {
                    setCursorStyle('grab');
                    return;
                }
            }

            setCursorStyle('default');
        },
        [toolMode, selectedFieldId, currentPageFields, pageDimensions, zoom, getMousePos],
    );

    // ------ Scroll wheel zoom (native listener to allow preventDefault on non-passive) ------
    useEffect(() => {
        const canvas = canvasRef.current;
        if (!canvas) return;

        const onWheel = (e: WheelEvent) => {
            e.preventDefault();
            if (e.deltaY < 0) {
                setZoom((z) => ZOOM_LEVELS.find((l) => l > z) ?? ZOOM_MAX);
            } else if (e.deltaY > 0) {
                setZoom((z) => [...ZOOM_LEVELS].reverse().find((l) => l < z) ?? ZOOM_MIN);
            }
        };

        canvas.addEventListener('wheel', onWheel, { passive: false });
        return () => canvas.removeEventListener('wheel', onWheel);
    }, [setZoom]);

    if (canvasWidth <= 0 || canvasHeight <= 0) return null;

    const cursor = toolMode === 'pan' ? cursorStyle : toolMode === 'select' ? cursorStyle : 'crosshair';

    return (
        <canvas
            ref={canvasRef}
            width={canvasWidth}
            height={canvasHeight}
            style={{
                position: 'absolute',
                top: 0,
                left: 0,
                width: canvasWidth,
                height: canvasHeight,
                pointerEvents: 'auto',
                cursor,
            }}
            onMouseDown={handleMouseDown}
            onMouseMove={handleMouseMove}
            onAuxClick={(e) => e.button === 1 && e.preventDefault()}
        />
    );
};

export default FieldOverlay;
