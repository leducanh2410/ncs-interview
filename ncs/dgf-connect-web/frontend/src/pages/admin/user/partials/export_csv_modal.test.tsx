import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { buildUserExportFilters } from '@/pages/admin/user/build_export_filters';
import ExportCsvModal from './export_csv_modal';
import { usePermissions } from '@/hooks/usePermission';
import { useCreateCsvExportMutation, useGetCsvPropertiesQuery } from '@/pages/admin/csv-export/api';

// Mock dependencies
vi.mock('@tanstack/react-query', () => ({
    useQuery: vi.fn(),
    useMutation: vi.fn(),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('@/pages/admin/csv-export/api', () => ({
    useGetCsvPropertiesQuery: vi.fn(),
    useCreateCsvExportMutation: vi.fn(),
}));

vi.mock('@/types/models/csv_export', () => ({
    CsvPropertyMeta: class {},
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class extends Error {
        status: number;
        constructor(message: string, status: number) {
            super(message);
            this.status = status;
        }
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showError: vi.fn(),
        showSuccess: vi.fn(),
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: vi.fn(
            (route: string, params: Record<string, string | number>) => `/admin/user/export-history/${params.id}`,
        ),
    },
}));

vi.mock('@/pages/admin/csv-export/csv_property_label', () => ({
    getCsvPropertyLabel: vi.fn((key: string) => key),
}));

vi.mock('next/link', () => ({
    default: function MockLink({ children, href }: { children: React.ReactNode; href: string }) {
        return React.createElement('a', { href }, children);
    },
}));

vi.mock('@mantine/notifications', () => ({
    notifications: {
        show: vi.fn(),
    },
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, disabled, loading, leftSection, ...props }: any) =>
        React.createElement('button', { ...props, disabled: disabled || loading }, children),
    Checkbox: ({ checked, onChange, disabled }: any) =>
        React.createElement('input', {
            type: 'checkbox',
            checked,
            onChange,
            disabled,
        }),
    Flex: ({ children, ...props }: any) => React.createElement('div', props, children),
    Group: ({ children, ...props }: any) => React.createElement('div', props, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Select: ({ data, value, onChange, label }: any) =>
        React.createElement(
            'select',
            {
                'aria-label': label,
                value,
                'onChange': (e: any) => onChange(e.target.value),
            },
            data?.map((opt: any) => React.createElement('option', { key: opt.value, value: opt.value }, opt.label)),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@hello-pangea/dnd', () => ({
    DragDropContext: ({ children }: any) => React.createElement('div', null, children),
    Droppable: ({ children }: any) =>
        React.createElement('div', null, children({ droppableProps: {}, innerRef: null, placeholder: null })),
    Draggable: ({ children }: any) =>
        React.createElement(
            'div',
            null,
            children({ draggableProps: {}, dragHandleProps: {}, innerRef: null }, { isDragging: false }),
        ),
}));

vi.mock('react-icons/lu', () => ({
    LuFileText: () => React.createElement('span', null, '📄'),
    LuGripVertical: () => React.createElement('span', null, '⋮'),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (options && 'count' in options) {
                return `${key} (${options.count})`;
            }
            if (options && 'selected' in options && 'total' in options) {
                return `${options.selected} / ${options.total} selected`;
            }
            return key;
        },
    }),
}));

const mockProperties = [
    { key: 'first_name', label_ja: 'First name' },
    { key: 'last_name', label_ja: 'Last name' },
    { key: 'email', label_ja: 'Email' },
    { key: 'phone', label_ja: 'Phone' },
];

const createMockMutateAsync = vi.fn();

function setupMocks(
    options: {
        localStorageData?: string | null;
        mutationPending?: boolean;
        mutationError?: Error | null;
    } = {},
) {
    const { localStorageData = null, mutationPending = false, mutationError = null } = options;

    vi.mocked(usePermissions).mockReturnValue({ USER_EXPORT_CSV: true } as ReturnType<typeof usePermissions>);

    vi.mocked(useGetCsvPropertiesQuery).mockReturnValue({
        data: { data: mockProperties },
        isPending: false,
    } as ReturnType<typeof useGetCsvPropertiesQuery>);

    vi.mocked(useCreateCsvExportMutation).mockReturnValue({
        mutateAsync: createMockMutateAsync,
        isPending: mutationPending,
        error: mutationError,
    } as unknown as ReturnType<typeof useCreateCsvExportMutation>);

    (localStorage.getItem as ReturnType<typeof vi.fn>).mockImplementation((key: string) => {
        if (key === 'user_csv_export_config') {
            return localStorageData;
        }
        return null;
    });

    (localStorage.setItem as ReturnType<typeof vi.fn>).mockImplementation(() => {});
}

function renderModal(props: Partial<React.ComponentProps<typeof ExportCsvModal>> = {}) {
    const defaultProps: React.ComponentProps<typeof ExportCsvModal> = {
        isOpened: true,
        onClose: vi.fn(),
        selectedUserIds: [1, 2, 3],
        selectAll: false,
        totalUsers: 100,
        filters: buildUserExportFilters({}),
    };

    return render(<ExportCsvModal {...defaultProps} {...props} />);
}

describe('ExportCsvModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        createMockMutateAsync.mockReset();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('localStorage loading', () => {
        it('loads config from localStorage when available', async () => {
            const storedConfig = JSON.stringify({
                selected: ['last_name', 'email'],
                order: ['last_name', 'first_name', 'email', 'phone'],
                encoding: 'SHIFT_JIS',
            });
            setupMocks({ localStorageData: storedConfig });

            renderModal();

            await waitFor(() => {
                expect(screen.getByText('last_name')).toBeInTheDocument();
            });

            // Check that checkboxes reflect stored selection
            const checkboxes = screen.getAllByRole('checkbox');
            expect(checkboxes[0]).toBeChecked(); // last_name
            expect(checkboxes[1]).not.toBeChecked(); // first_name
            expect(checkboxes[2]).toBeChecked(); // email
            expect(checkboxes[3]).not.toBeChecked(); // phone
        });

        it('falls back to default config when localStorage is empty', async () => {
            setupMocks({ localStorageData: null });

            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            // All should be selected by default
            const checkboxes = screen.getAllByRole('checkbox');
            expect(checkboxes).toHaveLength(4);
            checkboxes.forEach((cb) => {
                expect(cb).toBeChecked();
            });
        });

        it('falls back to default config when localStorage contains invalid JSON', async () => {
            setupMocks({ localStorageData: 'invalid-json{' });

            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const checkboxes = screen.getAllByRole('checkbox');
            checkboxes.forEach((cb) => {
                expect(cb).toBeChecked();
            });
        });

        it('drops unknown keys and appends missing keys when loading from localStorage', async () => {
            const storedConfig = JSON.stringify({
                selected: ['unknown_key', 'email'],
                order: ['unknown_key', 'email'],
            });
            setupMocks({ localStorageData: storedConfig });

            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            // Should have all 4 properties, with unknown dropped and missing appended
            const checkboxes = screen.getAllByRole('checkbox');
            expect(checkboxes).toHaveLength(4);

            // Order should be: email, first_name, last_name, phone (unknown dropped, missing appended)
            // First in order is email (selected), then first_name, last_name, phone (deselected as missing)
            expect(checkboxes[0]).toBeChecked(); // email
            expect(checkboxes[1]).not.toBeChecked(); // first_name
            expect(checkboxes[2]).not.toBeChecked(); // last_name
            expect(checkboxes[3]).not.toBeChecked(); // phone
        });
    });

    describe('toggle behavior', () => {
        it('updates selected state when toggling checkboxes', async () => {
            setupMocks();
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const checkboxes = screen.getAllByRole('checkbox');
            expect(checkboxes[0]).toBeChecked(); // first_name

            await userEvent.click(checkboxes[0]);

            expect(checkboxes[0]).not.toBeChecked();

            await userEvent.click(checkboxes[0]);

            expect(checkboxes[0]).toBeChecked();
        });

        it('does not write to localStorage on toggle', async () => {
            setupMocks();
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const checkboxes = screen.getAllByRole('checkbox');
            await userEvent.click(checkboxes[0]);

            expect(localStorage.setItem).not.toHaveBeenCalled();
        });
    });

    describe('reset to default', () => {
        it('replaces in-modal state with default config', async () => {
            const storedConfig = JSON.stringify({
                selected: ['email'],
                order: ['email', 'phone', 'first_name', 'last_name'],
            });
            setupMocks({ localStorageData: storedConfig });
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            // Initially only email is selected
            const checkboxes = screen.getAllByRole('checkbox');
            expect(checkboxes[0]).toBeChecked(); // email
            expect(checkboxes[1]).not.toBeChecked(); // phone

            const resetButton = screen.getByRole('button', { name: /Reset to default/i });
            await userEvent.click(resetButton);

            // After reset, all should be selected and in default order
            checkboxes.forEach((cb) => {
                expect(cb).toBeChecked();
            });
        });

        it('does not write to localStorage when clicking reset', async () => {
            const storedConfig = JSON.stringify({
                selected: ['email'],
                order: ['email', 'phone'],
            });
            setupMocks({ localStorageData: storedConfig });
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const resetButton = screen.getByRole('button', { name: /Reset to default/i });
            await userEvent.click(resetButton);

            expect(localStorage.setItem).not.toHaveBeenCalled();
        });
    });

    describe('submit behavior', () => {
        it('writes localStorage and calls mutation on submit', async () => {
            setupMocks();
            createMockMutateAsync.mockResolvedValue({
                data: { id: 123 },
            });

            const onClose = vi.fn();
            renderModal({ onClose, selectedUserIds: [1, 2] });

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const exportButton = screen.getByRole('button', { name: /Export/i });
            await userEvent.click(exportButton);

            await waitFor(() => {
                expect(localStorage.setItem).toHaveBeenCalledWith('user_csv_export_config', expect.any(String));
            });

            const setItemCall = (localStorage.setItem as ReturnType<typeof vi.fn>).mock.calls.find(
                (call: any[]) => call[0] === 'user_csv_export_config',
            );
            expect(setItemCall).toBeDefined();
            const storedConfig = JSON.parse(setItemCall![1]);
            expect(storedConfig.encoding).toBe('UTF_8');

            expect(createMockMutateAsync).toHaveBeenCalledWith(
                expect.objectContaining({
                    selected_keys: expect.arrayContaining(['first_name', 'last_name', 'email', 'phone']),
                    ordered_keys: expect.arrayContaining(['first_name', 'last_name', 'email', 'phone']),
                    encoding: 'utf-8',
                    user_ids: [1, 2],
                    select_all: false,
                }),
            );
        });

        it('shows error when no properties selected', async () => {
            setupMocks();
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            // Deselect all
            const checkboxes = screen.getAllByRole('checkbox');
            for (const cb of checkboxes) {
                if (cb instanceof HTMLInputElement && cb.checked) {
                    await userEvent.click(cb);
                }
            }

            const exportButton = screen.getByRole('button', { name: /Export/i });
            expect(exportButton).toBeDisabled();
        });

        it('shows error when no users selected', async () => {
            setupMocks();
            renderModal({ selectedUserIds: [], selectAll: false });

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const exportButton = screen.getByRole('button', { name: /Export/i });
            expect(exportButton).toBeDisabled();
        });

        it('passes select_all and filters when selectAll is true', async () => {
            setupMocks();
            createMockMutateAsync.mockResolvedValue({
                data: { id: 456 },
            });

            const filters = buildUserExportFilters({
                workplace_id: '1',
                info_search_field: 'passport_number',
                info_search_value: 'PASS',
                groups: ['3', '4'],
            });
            renderModal({ selectAll: true, totalUsers: 50, filters });

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const exportButton = screen.getByRole('button', { name: /Export/i });
            await userEvent.click(exportButton);

            await waitFor(() => {
                expect(createMockMutateAsync).toHaveBeenCalledWith(
                    expect.objectContaining({
                        select_all: true,
                        filters,
                        encoding: 'utf-8',
                    }),
                );
            });
        });

        it('preserves custom order in payload', async () => {
            const storedConfig = JSON.stringify({
                selected: ['email', 'first_name'],
                order: ['email', 'first_name', 'last_name', 'phone'],
                encoding: 'SHIFT_JIS',
            });
            setupMocks({ localStorageData: storedConfig });
            createMockMutateAsync.mockResolvedValue({
                data: { id: 789 },
            });

            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const exportButton = screen.getByRole('button', { name: /Export/i });
            await userEvent.click(exportButton);

            await waitFor(() => {
                expect(createMockMutateAsync).toHaveBeenCalledWith(
                    expect.objectContaining({
                        selected_keys: ['email', 'first_name'],
                        ordered_keys: ['email', 'first_name', 'last_name', 'phone'],
                        encoding: 'shift-jis',
                    }),
                );
            });
        });
    });

    describe('export button state', () => {
        it('is disabled when zero properties are selected', async () => {
            setupMocks();
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const checkboxes = screen.getAllByRole('checkbox');
            for (const cb of checkboxes) {
                if (cb instanceof HTMLInputElement && cb.checked) {
                    await userEvent.click(cb);
                }
            }

            const exportButton = screen.getByRole('button', { name: /Export/i });
            expect(exportButton).toBeDisabled();
        });

        it('is disabled when properties are still loading', async () => {
            vi.mocked(usePermissions).mockReturnValue({ USER_EXPORT_CSV: true } as ReturnType<typeof usePermissions>);
            vi.mocked(useGetCsvPropertiesQuery).mockReturnValue({
                data: undefined,
                isPending: true,
            } as ReturnType<typeof useGetCsvPropertiesQuery>);
            vi.mocked(useCreateCsvExportMutation).mockReturnValue({
                mutateAsync: createMockMutateAsync,
                isPending: false,
            } as unknown as ReturnType<typeof useCreateCsvExportMutation>);

            renderModal();

            const exportButton = screen.getByRole('button', { name: /Export/i });
            expect(exportButton).toBeDisabled();
        });

        it('is disabled when mutation is pending', async () => {
            setupMocks({ mutationPending: true });
            renderModal();

            await waitFor(() => {
                expect(screen.getByText('first_name')).toBeInTheDocument();
            });

            const exportButton = screen.getByRole('button', { name: /Export/i });
            expect(exportButton).toBeDisabled();
        });
    });
});
