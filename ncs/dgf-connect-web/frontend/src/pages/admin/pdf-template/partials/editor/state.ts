import { atom } from 'jotai';
import { PdfTemplateField, PdfTemplateFieldShape } from '@/types/models/pdf_template';
import { SavePdfTemplateFieldPayload } from '@/pages/admin/pdf-template/api';

export type ToolMode = 'select' | 'pan' | 'draw-rect' | 'draw-circle';

export const ZOOM_LEVELS = [0.5, 1, 1.5, 2, 2.5, 3, 3.5, 4, 4.5, 5];
export const ZOOM_MIN = ZOOM_LEVELS[0];
export const ZOOM_MAX = ZOOM_LEVELS[ZOOM_LEVELS.length - 1];

export type EditorField = SavePdfTemplateFieldPayload & {
    _localId: string;
};

export const fieldsAtom = atom<EditorField[]>([]);
export const selectedFieldIdAtom = atom<string | null>(null);
export const zoomAtom = atom<number>(1);
export const currentPageAtom = atom<number>(1);
export const toolModeAtom = atom<ToolMode>('select');
export const clipboardAtom = atom<EditorField | null>(null);
export const isDirtyAtom = atom<boolean>(false);

export const selectedFieldAtom = atom(
    (get) => {
        const id = get(selectedFieldIdAtom);
        if (!id) return null;
        return get(fieldsAtom).find((f) => f._localId === id) ?? null;
    },
    (get, set, update: Partial<EditorField>) => {
        const id = get(selectedFieldIdAtom);
        if (!id) return;
        set(
            fieldsAtom,
            get(fieldsAtom).map((f) => (f._localId === id ? { ...f, ...update } : f)),
        );
    },
);

export const toEditorField = (field: PdfTemplateField): EditorField => ({
    _localId: `server-${field.id}`,
    id: field.id,
    page: field.page,
    shape: field.shape,
    x: field.x,
    y: field.y,
    width: field.width,
    height: field.height,
    data_source_key: field.data_source_key,
    placeholder: field.placeholder,
    invert_value: field.invert_value,
    fill_mode: field.fill_mode,
    text_config: field.text_config,
    shape_config: field.shape_config,
});

export const newEditorField = (
    page: number,
    shape: PdfTemplateFieldShape,
    x: number,
    y: number,
    width: number,
    height: number,
): EditorField => ({
    _localId: `new-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    id: 0,
    page,
    shape,
    x,
    y,
    width,
    height,
    data_source_key: '',
    placeholder: null,
    invert_value: false,
    fill_mode: 'text',
    text_config: {
        font_weight: 'normal',
        font_style: 'normal',
        font_size: 12,
        h_align: 'center',
        v_align: 'middle',
        letter_spacing: 0,
        overflow: 'wrap',
    },
    shape_config: null,
});
