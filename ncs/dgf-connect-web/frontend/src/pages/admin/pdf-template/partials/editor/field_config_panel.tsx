import React, { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
    LuAlignCenter,
    LuAlignLeft,
    LuAlignRight,
    LuAlignVerticalJustifyCenter,
    LuAlignVerticalJustifyEnd,
    LuAlignVerticalJustifyStart,
    LuBold,
    LuItalic,
} from 'react-icons/lu';
import {
    ActionIcon,
    Box,
    Checkbox,
    ColorInput,
    Divider,
    Group,
    NumberInput,
    Radio,
    ScrollArea,
    SegmentedControl,
    Select,
    Stack,
    Text,
    TextInput,
    Tooltip,
} from '@mantine/core';
import { useAtom } from 'jotai';
import {
    PdfTemplateFieldFillMode,
    PdfTemplateShapeFillStyle,
    PdfTemplateTextFontStyle,
    PdfTemplateTextFontWeight,
    PdfTemplateTextHorizontalAlign,
    PdfTemplateTextOverflow,
    PdfTemplateTextVerticalAlign,
    ShapeConfig,
    TextConfig,
} from '@/types/models/pdf_template';
import { selectedFieldAtom } from '@/pages/admin/pdf-template/partials/editor/state';
import { useGetDataSourcesQuery } from '@/pages/admin/pdf-template/api';

type FieldConfigPanelProps = {
    hasPermission: boolean;
    isReadOnly: boolean;
    onMarkDirty: () => void;
    validationErrors: Record<string, string>;
};

const DEFAULT_TEXT_CONFIG: TextConfig = {
    font_weight: 'normal',
    font_style: 'normal',
    font_size: 12,
    h_align: 'center',
    v_align: 'middle',
    letter_spacing: 0,
    overflow: 'wrap',
};

const DEFAULT_SHAPE_CONFIG: ShapeConfig = {
    fill_style: 'filled',
    color: '#000000',
    stroke_width: 0.25,
};

const FieldConfigPanel = ({ hasPermission, isReadOnly, onMarkDirty, validationErrors }: FieldConfigPanelProps) => {
    const { t } = useTranslation();
    const [field, setField] = useAtom(selectedFieldAtom);
    const { data: dataSourcesResponse } = useGetDataSourcesQuery(hasPermission);

    const dataSourceOptions = useMemo(() => {
        const sources = dataSourcesResponse?.data;
        if (!sources || !Array.isArray(sources)) return [];

        // Group items for Mantine Select grouped format
        const groups = new Map<string, { value: string; label: string }[]>();
        const fixedGroup: { value: string; label: string }[] = [];

        for (const ds of sources) {
            const group = ds.group || t('Other');

            // Collect fixed value items separately to add at the end
            if (ds.key === 'fixed_value') {
                fixedGroup.push({ value: ds.key, label: ds.name });
                continue;
            }

            if (!groups.has(group)) groups.set(group, []);
            groups.get(group)!.push({ value: ds.key, label: ds.name });
        }

        const result = Array.from(groups.entries()).map(([group, items]) => ({
            group,
            items,
        }));

        // Add fixed value group at the end
        if (fixedGroup.length > 0) {
            result.push({
                group: t('Fixed'),
                items: fixedGroup,
            });
        }

        return result;
    }, [dataSourcesResponse, t]);

    const reversibleDataSourceKeys = useMemo(() => {
        const keys = new Set<string>();
        const sources = dataSourcesResponse?.data;
        if (!sources || !Array.isArray(sources)) return keys;
        for (const ds of sources) {
            if (ds.is_reversible) keys.add(ds.key);
        }
        return keys;
    }, [dataSourcesResponse]);

    const updateField = (update: Record<string, unknown>) => {
        setField(update);
        onMarkDirty();
    };

    const updateTextConfig = (key: keyof TextConfig, value: TextConfig[keyof TextConfig]) => {
        const current = field?.text_config ?? DEFAULT_TEXT_CONFIG;
        updateField({ text_config: { ...current, [key]: value } });
    };

    const updateShapeConfig = (key: keyof ShapeConfig, value: ShapeConfig[keyof ShapeConfig]) => {
        const current = field?.shape_config ?? DEFAULT_SHAPE_CONFIG;
        updateField({ shape_config: { ...current, [key]: value } });
    };

    if (!field) {
        return (
            <Box p="md">
                <Text size="sm" c="dimmed">
                    {t('Select a field to configure')}
                </Text>
            </Box>
        );
    }

    const textConfig = field.text_config ?? DEFAULT_TEXT_CONFIG;
    const shapeConfig = field.shape_config ?? DEFAULT_SHAPE_CONFIG;
    const isShrink = textConfig.overflow === 'shrink';
    const getError = (path: string) => validationErrors[path];
    const getControlErrorStyle = (path: string) =>
        getError(path)
            ? {
                  border: '1px solid var(--mantine-color-red-6)',
                  borderRadius: 'var(--mantine-radius-sm)',
                  padding: 4,
              }
            : undefined;

    return (
        <ScrollArea h="100%" offsetScrollbars>
            <Stack gap="sm" p="md">
                {/* Read-only info */}
                <Box>
                    <Text size="xs" fw={500} c="dimmed" mb={2}>
                        {t('Shape')}
                    </Text>
                    <Text size="sm" tt="capitalize">
                        {t(field.shape)}
                    </Text>
                </Box>

                <Box>
                    <Text size="xs" fw={500} c="dimmed" mb={2}>
                        {t('Page')}
                    </Text>
                    <Text size="sm">{field.page}</Text>
                </Box>

                <Divider />

                {/* Data source */}
                <Select
                    label={t('Data source')}
                    placeholder={t('Select data source')}
                    data={dataSourceOptions}
                    value={field.data_source_key || null}
                    onChange={(value) => updateField({ data_source_key: value ?? '' })}
                    error={getError('data_source_key')}
                    searchable
                    clearable
                    disabled={isReadOnly}
                />

                {reversibleDataSourceKeys.has(field.data_source_key) && (
                    <Checkbox
                        label={t('Invert value')}
                        checked={field.invert_value}
                        onChange={(e) => updateField({ invert_value: e.currentTarget.checked })}
                        disabled={isReadOnly}
                    />
                )}

                {/* Placeholder / Fixed Value */}
                <TextInput
                    label={field.data_source_key === 'fixed_value' ? t('Value') : t('Placeholder')}
                    placeholder={
                        field.data_source_key === 'fixed_value'
                            ? t('Enter fixed value')
                            : t('Override default placeholder')
                    }
                    value={field.placeholder ?? ''}
                    onChange={(e) => updateField({ placeholder: e.currentTarget.value || null })}
                    error={getError('placeholder')}
                    disabled={isReadOnly}
                    required={field.data_source_key === 'fixed_value'}
                />

                <Divider />

                {/* Fill mode */}
                <Radio.Group
                    label={t('Fill mode')}
                    value={field.fill_mode}
                    error={getError('fill_mode')}
                    onChange={(value) => {
                        const fillMode = value as PdfTemplateFieldFillMode;
                        const update: Record<string, unknown> = { fill_mode: fillMode };
                        if (fillMode === 'text' && !field.text_config) {
                            update.text_config = { ...DEFAULT_TEXT_CONFIG };
                        } else if (fillMode === 'shape' && !field.shape_config) {
                            update.shape_config = { ...DEFAULT_SHAPE_CONFIG };
                        }
                        updateField(update);
                    }}
                >
                    <Radio value="text" label={t('Text')} disabled={isReadOnly} mt={4} />
                    <Radio value="shape" label={t('Shape')} disabled={isReadOnly} mt={4} />
                </Radio.Group>

                <Divider />

                {/* Text config */}
                {field.fill_mode === 'text' && (
                    <Stack gap="sm">
                        <Text size="sm" fw={600}>
                            {t('Text settings')}
                        </Text>
                        {getError('text_config') && (
                            <Text size="xs" c="red">
                                {getError('text_config')}
                            </Text>
                        )}

                        {/* Font style + size */}
                        <Box>
                            <Text size="sm" fw={500} mb={4}>
                                {t('Font')}
                            </Text>
                            <Group gap="xs" wrap="nowrap">
                                <Tooltip label={t('Bold')}>
                                    <ActionIcon
                                        variant={textConfig.font_weight === 'bold' ? 'filled' : 'default'}
                                        size="lg"
                                        onClick={() =>
                                            updateTextConfig(
                                                'font_weight',
                                                textConfig.font_weight === 'bold'
                                                    ? 'normal'
                                                    : ('bold' as PdfTemplateTextFontWeight),
                                            )
                                        }
                                        disabled={isReadOnly}
                                    >
                                        <LuBold size={16} />
                                    </ActionIcon>
                                </Tooltip>
                                <Tooltip label={t('Italic')}>
                                    <ActionIcon
                                        variant={textConfig.font_style === 'italic' ? 'filled' : 'default'}
                                        size="lg"
                                        onClick={() =>
                                            updateTextConfig(
                                                'font_style',
                                                textConfig.font_style === 'italic'
                                                    ? 'normal'
                                                    : ('italic' as PdfTemplateTextFontStyle),
                                            )
                                        }
                                        disabled={isReadOnly}
                                    >
                                        <LuItalic size={16} />
                                    </ActionIcon>
                                </Tooltip>
                                <NumberInput
                                    value={textConfig.font_size}
                                    onChange={(value) =>
                                        updateTextConfig('font_size', typeof value === 'number' ? value : 12)
                                    }
                                    min={4}
                                    max={72}
                                    error={getError('text_config.font_size')}
                                    disabled={isReadOnly || isShrink}
                                    style={{ flex: 1 }}
                                    size="sm"
                                />
                            </Group>
                            {isShrink && (
                                <Text size="xs" c="dimmed" mt={2}>
                                    {t('Auto-sized in shrink mode')}
                                </Text>
                            )}
                        </Box>

                        {/* H-align */}
                        <Box>
                            <Text size="sm" fw={500} mb={4}>
                                {t('Horizontal align')}
                            </Text>
                            <Box style={getControlErrorStyle('text_config.h_align')}>
                                <SegmentedControl
                                    fullWidth
                                    size="xs"
                                    value={textConfig.h_align}
                                    onChange={(value) =>
                                        updateTextConfig('h_align', value as PdfTemplateTextHorizontalAlign)
                                    }
                                    data={[
                                        {
                                            value: 'left',
                                            label: (
                                                <Tooltip label={t('Left')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignLeft size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                        {
                                            value: 'center',
                                            label: (
                                                <Tooltip label={t('Center')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignCenter size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                        {
                                            value: 'right',
                                            label: (
                                                <Tooltip label={t('Right')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignRight size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                    ]}
                                    disabled={isReadOnly}
                                />
                            </Box>
                            {getError('text_config.h_align') && (
                                <Text size="xs" c="red" mt={4}>
                                    {getError('text_config.h_align')}
                                </Text>
                            )}
                        </Box>

                        {/* V-align */}
                        <Box>
                            <Text size="sm" fw={500} mb={4}>
                                {t('Vertical align')}
                            </Text>
                            <Box style={getControlErrorStyle('text_config.v_align')}>
                                <SegmentedControl
                                    fullWidth
                                    size="xs"
                                    value={textConfig.v_align}
                                    onChange={(value) =>
                                        updateTextConfig('v_align', value as PdfTemplateTextVerticalAlign)
                                    }
                                    data={[
                                        {
                                            value: 'top',
                                            label: (
                                                <Tooltip label={t('Top')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignVerticalJustifyStart size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                        {
                                            value: 'middle',
                                            label: (
                                                <Tooltip label={t('Middle')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignVerticalJustifyCenter size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                        {
                                            value: 'bottom',
                                            label: (
                                                <Tooltip label={t('Bottom')}>
                                                    <Box display="flex" style={{ justifyContent: 'center' }}>
                                                        <LuAlignVerticalJustifyEnd size={14} />
                                                    </Box>
                                                </Tooltip>
                                            ),
                                        },
                                    ]}
                                    disabled={isReadOnly}
                                />
                            </Box>
                            {getError('text_config.v_align') && (
                                <Text size="xs" c="red" mt={4}>
                                    {getError('text_config.v_align')}
                                </Text>
                            )}
                        </Box>

                        {/* Letter spacing */}
                        <Select
                            label={t('Letter spacing')}
                            value={textConfig.letter_spacing === 'auto' ? 'auto' : 'fixed'}
                            onChange={(value) => {
                                if (value === 'auto') {
                                    updateTextConfig('letter_spacing', 'auto');
                                } else {
                                    updateTextConfig('letter_spacing', 0);
                                }
                            }}
                            data={[
                                { value: 'auto', label: t('Auto (distribute evenly)') },
                                { value: 'fixed', label: t('Fixed value') },
                            ]}
                            allowDeselect={false}
                            error={getError('text_config.letter_spacing')}
                            disabled={isReadOnly}
                        />

                        {textConfig.letter_spacing !== 'auto' && (
                            <NumberInput
                                label={t('Spacing value')}
                                value={typeof textConfig.letter_spacing === 'number' ? textConfig.letter_spacing : 0}
                                onChange={(value) =>
                                    updateTextConfig('letter_spacing', typeof value === 'number' ? value : 0)
                                }
                                min={0}
                                max={50}
                                step={0.5}
                                decimalScale={1}
                                error={getError('text_config.letter_spacing')}
                                disabled={isReadOnly}
                            />
                        )}

                        {/* Overflow */}
                        <Select
                            label={t('Overflow')}
                            value={textConfig.overflow}
                            onChange={(value) =>
                                updateTextConfig('overflow', (value ?? 'wrap') as PdfTemplateTextOverflow)
                            }
                            data={[
                                { value: 'wrap', label: t('Wrap') },
                                { value: 'trim', label: t('Trim') },
                                { value: 'shrink', label: t('Shrink to fit') },
                            ]}
                            allowDeselect={false}
                            error={getError('text_config.overflow')}
                            disabled={isReadOnly}
                        />
                    </Stack>
                )}

                {/* Shape config */}
                {field.fill_mode === 'shape' && (
                    <Stack gap="sm">
                        <Text size="sm" fw={600}>
                            {t('Shape settings')}
                        </Text>
                        {getError('shape_config') && (
                            <Text size="xs" c="red">
                                {getError('shape_config')}
                            </Text>
                        )}

                        {/* Fill style */}
                        <Box>
                            <Text size="sm" fw={500} mb={4}>
                                {t('Fill style')}
                            </Text>
                            <Box style={getControlErrorStyle('shape_config.fill_style')}>
                                <SegmentedControl
                                    fullWidth
                                    size="xs"
                                    value={shapeConfig.fill_style}
                                    onChange={(value) =>
                                        updateShapeConfig('fill_style', value as PdfTemplateShapeFillStyle)
                                    }
                                    data={[
                                        { value: 'filled', label: t('Filled') },
                                        { value: 'outlined', label: t('Outlined') },
                                    ]}
                                    disabled={isReadOnly}
                                />
                            </Box>
                            {getError('shape_config.fill_style') && (
                                <Text size="xs" c="red" mt={4}>
                                    {getError('shape_config.fill_style')}
                                </Text>
                            )}
                        </Box>

                        {/* Color */}
                        <ColorInput
                            label={t('Color')}
                            value={shapeConfig.color}
                            onChange={(value) => updateShapeConfig('color', value)}
                            error={getError('shape_config.color')}
                            disabled={isReadOnly}
                        />

                        {/* Stroke width (outlined only) */}
                        {shapeConfig.fill_style === 'outlined' && (
                            <NumberInput
                                label={t('Stroke width')}
                                value={shapeConfig.stroke_width ?? 0.25}
                                onChange={(value) =>
                                    updateShapeConfig('stroke_width', typeof value === 'number' ? value : 0.25)
                                }
                                min={0.1}
                                max={10}
                                step={0.25}
                                decimalScale={2}
                                error={getError('shape_config.stroke_width')}
                                disabled={isReadOnly}
                            />
                        )}
                    </Stack>
                )}
            </Stack>
        </ScrollArea>
    );
};

export default FieldConfigPanel;
