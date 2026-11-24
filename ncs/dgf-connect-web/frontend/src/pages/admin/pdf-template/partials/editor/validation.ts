import i18n from '@/locales/i18n';
import { ApiValidationError } from '@/helpers/api.helper';
import { EditorField } from './state';

const FIELD_SHAPES = new Set(['rectangle', 'circle']);
const FILL_MODES = new Set(['text', 'shape']);
const FONT_WEIGHTS = new Set(['normal', 'bold']);
const H_ALIGNS = new Set(['left', 'center', 'right']);
const V_ALIGNS = new Set(['top', 'middle', 'bottom']);
const OVERFLOWS = new Set(['wrap', 'trim', 'shrink']);
const SHAPE_FILL_STYLES = new Set(['filled', 'outlined']);
const HEX_COLOR_REGEX = /^#[0-9A-Fa-f]{6}$/;
const t = i18n.t.bind(i18n);

const isFiniteNumber = (value: unknown): value is number => typeof value === 'number' && Number.isFinite(value);

type FieldValidationErrors = Record<string, string>;
export type FieldValidationErrorMap = Record<string, FieldValidationErrors>;

const setFieldError = (fieldErrorsById: FieldValidationErrorMap, fieldId: string, path: string, message: string) => {
    if (!fieldErrorsById[fieldId]) {
        fieldErrorsById[fieldId] = {};
    }

    if (!fieldErrorsById[fieldId][path]) {
        fieldErrorsById[fieldId][path] = message;
    }
};

export const validateEditorFields = (fields: EditorField[], validDataSourceKeys?: Set<string>) => {
    const fieldErrorsById: FieldValidationErrorMap = {};
    const shouldValidateDataSourceKey = !!validDataSourceKeys && validDataSourceKeys.size > 0;

    for (const field of fields) {
        if (!Number.isInteger(field.page) || field.page < 1) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field page must be at least 1'));
        }
        if (!FIELD_SHAPES.has(field.shape)) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field shape is invalid'));
        }
        if (!isFiniteNumber(field.x) || field.x < 0 || field.x > 100) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field position is invalid'));
        }
        if (!isFiniteNumber(field.y) || field.y < 0 || field.y > 100) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field position is invalid'));
        }
        if (!isFiniteNumber(field.width) || field.width < 0.01 || field.width > 100) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field size is invalid'));
        }
        if (!isFiniteNumber(field.height) || field.height < 0.01 || field.height > 100) {
            setFieldError(fieldErrorsById, field._localId, '__canvas', t('Field size is invalid'));
        }

        if (!field.data_source_key?.trim()) {
            setFieldError(fieldErrorsById, field._localId, 'data_source_key', t('Data source is required'));
        } else if (shouldValidateDataSourceKey && !validDataSourceKeys.has(field.data_source_key)) {
            setFieldError(fieldErrorsById, field._localId, 'data_source_key', t('Data source is invalid'));
        }

        if (field.data_source_key === 'fixed_value' && !field.placeholder?.trim()) {
            setFieldError(fieldErrorsById, field._localId, 'placeholder', t('Value is required for fixed value'));
        } else if (field.placeholder && field.placeholder.length > 255) {
            setFieldError(
                fieldErrorsById,
                field._localId,
                'placeholder',
                t('Placeholder must be 255 characters or less'),
            );
        }

        if (!FILL_MODES.has(field.fill_mode)) {
            setFieldError(fieldErrorsById, field._localId, 'fill_mode', t('Fill mode is invalid'));
        }

        if (field.fill_mode === 'text') {
            const textConfig = field.text_config;

            if (!textConfig) {
                setFieldError(fieldErrorsById, field._localId, 'text_config', t('Text settings are required'));
            } else {
                if (!FONT_WEIGHTS.has(textConfig.font_weight)) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'text_config.font_weight',
                        t('Font weight is required'),
                    );
                }
                if (!Number.isInteger(textConfig.font_size) || textConfig.font_size < 1 || textConfig.font_size > 72) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'text_config.font_size',
                        t('Font size must be an integer between 1 and 72'),
                    );
                }
                if (!H_ALIGNS.has(textConfig.h_align)) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'text_config.h_align',
                        t('Horizontal align is required'),
                    );
                }
                if (!V_ALIGNS.has(textConfig.v_align)) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'text_config.v_align',
                        t('Vertical align is required'),
                    );
                }
                if (textConfig.letter_spacing === null || typeof textConfig.letter_spacing === 'undefined') {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'text_config.letter_spacing',
                        t('Letter spacing is required'),
                    );
                }
                if (!OVERFLOWS.has(textConfig.overflow)) {
                    setFieldError(fieldErrorsById, field._localId, 'text_config.overflow', t('Overflow is required'));
                }
            }
        }

        if (field.fill_mode === 'shape') {
            const shapeConfig = field.shape_config;

            if (!shapeConfig) {
                setFieldError(fieldErrorsById, field._localId, 'shape_config', t('Shape settings are required'));
            } else {
                if (!SHAPE_FILL_STYLES.has(shapeConfig.fill_style)) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'shape_config.fill_style',
                        t('Fill style is required'),
                    );
                }
                if (!HEX_COLOR_REGEX.test(shapeConfig.color)) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'shape_config.color',
                        t('Color must be a valid hex color'),
                    );
                }
                if (
                    shapeConfig.stroke_width !== null &&
                    (!isFiniteNumber(shapeConfig.stroke_width) ||
                        shapeConfig.stroke_width < 0.1 ||
                        shapeConfig.stroke_width > 10)
                ) {
                    setFieldError(
                        fieldErrorsById,
                        field._localId,
                        'shape_config.stroke_width',
                        t('Stroke width must be between 0.1 and 10'),
                    );
                }
            }
        }
    }

    const invalidFieldIds: string[] = [];
    for (const field of fields) {
        const errors = fieldErrorsById[field._localId];
        if (errors && Object.keys(errors).length > 0) {
            invalidFieldIds.push(field._localId);
        }
    }

    return { invalidFieldIds, fieldErrorsById };
};

export const getInvalidFieldIdsFromApiValidationError = (error: ApiValidationError, fields: EditorField[]) => {
    const fieldErrorsById: FieldValidationErrorMap = {};
    const seen = new Set<string>();
    const invalidFieldIds: string[] = [];

    for (const key of Object.keys(error.data)) {
        const match = key.match(/^fields\.(\d+)(?:\.|$)/);
        if (!match) continue;

        const field = fields[Number(match[1])];
        if (!field) continue;

        const path = key.replace(/^fields\.\d+\./, '');
        const message = error.data[key]?.[0];

        if (message) {
            setFieldError(fieldErrorsById, field._localId, path || '__base', message);
        }

        if (!seen.has(field._localId)) {
            seen.add(field._localId);
            invalidFieldIds.push(field._localId);
        }
    }

    return { invalidFieldIds, fieldErrorsById };
};
