import React, { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { LuChevronDown, LuSearch, LuX } from 'react-icons/lu';
import {
    ActionIcon,
    Badge,
    Box,
    Button,
    Checkbox,
    Flex,
    Group,
    Input,
    Modal,
    ScrollArea,
    Stack,
    Text,
    TextInput,
} from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import WorkplaceHelper from '@/helpers/workplace.helper';
import { WorkplaceOption } from '@/pages/admin/dashboard/types';

interface Props {
    options: WorkplaceOption[];
    value?: number[];
    onChange: (next: number[]) => void;
    disabled?: boolean;
    /** Chip count shown inline before collapsing the rest into "+N more". */
    inlineChipLimit?: number;
}

const WorkplaceMultiSelect: React.FC<Props> = ({ options, value, onChange, disabled = false, inlineChipLimit = 3 }) => {
    const { t } = useTranslation();
    const [opened, { open, close }] = useDisclosure(false);
    const [search, setSearch] = useState('');
    const [draft, setDraft] = useState<number[]>([]);

    const selected = useMemo<number[]>(() => {
        if (value !== undefined) return value;
        return options.map((option) => option.id);
    }, [value, options]);

    const optionById = useMemo(() => {
        const map = new Map<number, WorkplaceOption>();
        options.forEach((option) => map.set(option.id, option));
        return map;
    }, [options]);

    const selectedOptions = useMemo(
        () =>
            selected
                .map((id) => optionById.get(id))
                .filter((option): option is WorkplaceOption => option !== undefined),
        [selected, optionById],
    );

    const filteredOptions = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return options;
        return options.filter((option) => option.name.toLowerCase().includes(q));
    }, [options, search]);

    useEffect(() => {
        if (opened) {
            setDraft(selected);
            setSearch('');
        }
    }, [opened, selected]);

    const inlineChips = selectedOptions.slice(0, inlineChipLimit);
    const overflowCount = Math.max(0, selectedOptions.length - inlineChipLimit);

    const allSelected = options.length > 0 && draft.length === options.length;
    const noneSelected = draft.length === 0;
    const visibleAllSelected =
        filteredOptions.length > 0 && filteredOptions.every((option) => draft.includes(option.id));

    const toggleDraft = (id: number) => {
        setDraft((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    const toggleAllVisible = () => {
        if (visibleAllSelected) {
            setDraft((prev) => prev.filter((id) => !filteredOptions.some((o) => o.id === id)));
        } else {
            const ids = new Set(draft);
            filteredOptions.forEach((option) => ids.add(option.id));
            setDraft(Array.from(ids));
        }
    };

    const handleClear = () => setDraft([]);
    const handleSelectAll = () => setDraft(options.map((option) => option.id));

    const handleApply = () => {
        onChange(draft);
        close();
    };

    const removeChip = (event: React.MouseEvent, id: number) => {
        event.stopPropagation();
        onChange(selected.filter((x) => x !== id));
    };

    const handleRemoveKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.stopPropagation();
        }
    };

    const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        if (disabled) return;
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            open();
        }
    };

    return (
        <Box>
            <Input.Wrapper label={t('Workplaces')}>
                <Box
                    role="button"
                    tabIndex={disabled ? -1 : 0}
                    aria-disabled={disabled}
                    aria-haspopup="dialog"
                    aria-expanded={opened}
                    onClick={disabled ? undefined : open}
                    onKeyDown={handleTriggerKeyDown}
                    style={{
                        width: '100%',
                        minWidth: 280,
                        border: '1px solid var(--mantine-color-gray-4)',
                        borderRadius: 'var(--mantine-radius-sm)',
                        padding: '6px 12px',
                        minHeight: 36,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        background: disabled ? 'var(--mantine-color-gray-0)' : 'white',
                        cursor: disabled ? 'not-allowed' : 'pointer',
                    }}
                >
                    <Flex gap={6} align="center" wrap="nowrap" style={{ overflow: 'hidden', flex: 1 }}>
                        {selectedOptions.length === 0 ? (
                            <Text size="sm" c="dimmed">
                                {t('Select workplaces')}
                            </Text>
                        ) : (
                            <>
                                {inlineChips.map((option) => (
                                    <Badge
                                        key={option.id}
                                        size="sm"
                                        variant="light"
                                        radius="sm"
                                        rightSection={
                                            !disabled && (
                                                <ActionIcon
                                                    size="xs"
                                                    variant="transparent"
                                                    color="gray"
                                                    onClick={(e) => removeChip(e, option.id)}
                                                    onKeyDown={handleRemoveKeyDown}
                                                    aria-label={t('Remove {{name}}', {
                                                        name: option.name,
                                                    })}
                                                >
                                                    <LuX size={10} />
                                                </ActionIcon>
                                            )
                                        }
                                        style={{ maxWidth: 160 }}
                                    >
                                        <Text
                                            span
                                            size="xs"
                                            style={{
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {WorkplaceHelper.formatLabel(option, t)}
                                        </Text>
                                    </Badge>
                                ))}
                                {overflowCount > 0 && (
                                    <Badge size="sm" variant="filled" color="gray" radius="sm">
                                        {t('+{{count}} more', { count: overflowCount })}
                                    </Badge>
                                )}
                            </>
                        )}
                    </Flex>
                    <LuChevronDown size={14} />
                </Box>
            </Input.Wrapper>

            <Modal opened={opened} onClose={close} title={t('Select workplaces')} size="md">
                <Stack gap="sm">
                    <TextInput
                        placeholder={t('Search workplaces')}
                        leftSection={<LuSearch size={14} />}
                        value={search}
                        onChange={(e) => setSearch(e.currentTarget.value)}
                    />
                    <Group justify="space-between">
                        <Group gap="xs">
                            <Button size="xs" variant="light" onClick={handleSelectAll} disabled={allSelected}>
                                {t('Select all')}
                            </Button>
                            <Button
                                size="xs"
                                variant="subtle"
                                color="gray"
                                onClick={handleClear}
                                disabled={noneSelected}
                            >
                                {t('Clear')}
                            </Button>
                        </Group>
                        <Text size="xs" c="dimmed">
                            {t('{{selected}} of {{total}} selected', {
                                selected: draft.length,
                                total: options.length,
                            })}
                        </Text>
                    </Group>

                    {filteredOptions.length === 0 ? (
                        <Box py="md" ta="center">
                            <Text size="sm" c="dimmed">
                                {t('No workplaces match the search.')}
                            </Text>
                        </Box>
                    ) : (
                        <ScrollArea.Autosize mah={360} type="auto" offsetScrollbars>
                            <Stack gap={4} pr="xs">
                                {search.trim() !== '' && (
                                    <Checkbox
                                        label={
                                            <Text size="sm" fw={500}>
                                                {visibleAllSelected
                                                    ? t('Deselect all matching')
                                                    : t('Select all matching')}
                                            </Text>
                                        }
                                        checked={visibleAllSelected}
                                        onChange={toggleAllVisible}
                                    />
                                )}
                                {filteredOptions.map((option) => (
                                    <Checkbox
                                        key={option.id}
                                        label={WorkplaceHelper.formatLabel(option, t)}
                                        checked={draft.includes(option.id)}
                                        onChange={() => toggleDraft(option.id)}
                                    />
                                ))}
                            </Stack>
                        </ScrollArea.Autosize>
                    )}

                    <Group justify="flex-end" mt="sm">
                        <Button variant="default" onClick={close}>
                            {t('Cancel')}
                        </Button>
                        <Button onClick={handleApply}>{t('Apply')}</Button>
                    </Group>
                </Stack>
            </Modal>
        </Box>
    );
};

export default WorkplaceMultiSelect;
