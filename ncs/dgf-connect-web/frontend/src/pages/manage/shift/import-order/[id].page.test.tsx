import React from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import ShiftOrderImportPage from '@/pages/manage/shift/import-order/[id].page';

const {
    usePermissionsMock,
    useAuthLoadedMock,
    previewQueryMock,
    confirmMutateMock,
    updateMonthMutateMock,
    refetchMock,
    pushMock,
    showSuccessMock,
} = vi.hoisted(() => ({
    usePermissionsMock: vi.fn(),
    useAuthLoadedMock: vi.fn(),
    previewQueryMock: vi.fn(),
    confirmMutateMock: vi.fn(),
    updateMonthMutateMock: vi.fn(),
    refetchMock: vi.fn(),
    pushMock: vi.fn(),
    showSuccessMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: { id: '7' }, push: pushMock, isReady: true }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;

            return Object.entries(options).reduce(
                (result, [placeholder, value]) => result.replace(`{{${placeholder}}}`, String(value)),
                key,
            );
        },
    }),
}));

vi.mock('dayjs', () => ({
    default: (value: unknown) => ({ format: () => String(value).slice(0, 7) }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title }: any) => React.createElement('div', { role: 'alert' }, title, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Card: ({ children }: any) => React.createElement('section', null, children),
    CardSection: ({ children }: any) => React.createElement('div', null, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    SegmentedControl: ({ value, onChange, data, color }: any) =>
        React.createElement(
            'div',
            { 'data-active-color': color },
            data.map((option: any) =>
                React.createElement(
                    'button',
                    {
                        'key': option.value,
                        'type': 'button',
                        'aria-pressed': value === option.value,
                        'onClick': () => onChange(option.value),
                    },
                    option.label,
                ),
            ),
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Table: Object.assign(({ children }: any) => React.createElement('table', null, children), {
        Thead: ({ children }: any) => React.createElement('thead', null, children),
        Tbody: ({ children }: any) => React.createElement('tbody', null, children),
        Tr: ({ children }: any) => React.createElement('tr', null, children),
        Th: ({ children }: any) => React.createElement('th', null, children),
        Td: ({ children }: any) => React.createElement('td', null, children),
    }),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/dates', () => ({
    MonthPickerInput: ({ label, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                'aria-label': 'month-input',
                'onChange': (event: React.ChangeEvent<HTMLInputElement>) => onChange(event.target.value),
            }),
        ),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (value: string) => value,
    },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: vi.fn(),
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
    useAuthLoaded: () => useAuthLoadedMock(),
}));

vi.mock('@/pages/manage/shift/import-order/api', () => ({
    useGetShiftOrderPreviewQuery: (...args: unknown[]) => previewQueryMock(...args),
    useUpdateShiftOrderMonthMutation: () => ({ mutateAsync: updateMonthMutateMock, isPending: false }),
    useConfirmShiftOrderImportMutation: () => ({ mutateAsync: confirmMutateMock, isPending: false }),
}));

const buildDay = (overrides: Record<string, unknown> = {}) => ({
    date: '2026-10-01',
    workplace_shift_id: 4,
    fixed_total: 7,
    spot_total: 2,
    positions: [{ workplace_position_id: 1, name: 'Position A', fixed_total: 5 }],
    existing: null,
    differences: [],
    status: 'new',
    ...overrides,
});

const buildPreview = (days: Record<string, unknown>[], summary: Record<string, number> = {}) => ({
    data: {
        data: {
            batch: { id: 7, file_name: 'order.xlsx' },
            month: '2026-10',
            detected_month: '2026-10',
            preview: {
                summary: {
                    new_count: days.filter((day) => day.status === 'new').length,
                    match_count: days.filter((day) => day.status === 'match').length,
                    diff_count: days.filter((day) => day.status === 'diff').length,
                    total_count: days.length,
                    ...summary,
                },
                workplaces: [
                    {
                        workplace_id: 5,
                        workplace_name: 'Musashino',
                        shifts: [
                            {
                                workplace_shift_id: 4,
                                shift_label: '22:00〜30:00',
                                start_time: '22:00',
                                end_time: '06:00',
                                days,
                            },
                        ],
                    },
                ],
            },
        },
    },
    isLoading: false,
    refetch: refetchMock,
});

const readComparisonLines = (cell: HTMLElement) =>
    Array.from(cell.querySelectorAll(':scope > div > div')).map((line) =>
        Array.from(line.children).map((part) => part.textContent),
    );

describe('Shift order import preview page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: true });
        useAuthLoadedMock.mockReturnValue(true);
        previewQueryMock.mockReturnValue(buildPreview([buildDay()]));
    });

    it('lists every day of a shift with its file numbers', () => {
        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        expect(within(row).getByText('7')).toBeInTheDocument();
        expect(within(row).getByText('Position A')).toBeInTheDocument();
        expect(within(row).getByText('5')).toBeInTheDocument();
        expect(within(row).getByText('spot(Unassigned)')).toBeInTheDocument();
        expect(within(row).getByText('2')).toBeInTheDocument();
        expect(within(row).getByText('Will be created')).toBeInTheDocument();
    });

    it('highlights the position whose headcount differs from the system', () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'diff',
                    differences: ['positions'],
                    positions: [{ workplace_position_id: 1, name: 'Position A', fixed_total: 5 }],
                    existing: {
                        shift_id: 9,
                        fixed_total: 7,
                        temp_total: 0,
                        assigned_count: 0,
                        unassigned_position_count: 0,
                        positions: [
                            { workplace_position_id: 1, name: 'Position A', fixed_total: 3, assigned_count: 0 },
                            { workplace_position_id: 2, name: 'Position B', fixed_total: 2, assigned_count: 0 },
                        ],
                    },
                }),
            ]),
        );

        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        expect(within(row).getAllByText('Position A')).toHaveLength(2);
        expect(within(row).getAllByText('Position B')).toHaveLength(2);
    });

    it('says so when a shift has no position breakdown', () => {
        previewQueryMock.mockReturnValue(buildPreview([buildDay({ positions: [], spot_total: 0 })]));

        render(<ShiftOrderImportPage />);

        expect(screen.getByText('No position breakdown')).toBeInTheDocument();
    });

    it('shows how many people are already assigned to each position of the existing shift', () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'match',
                    positions: [{ workplace_position_id: 1, name: 'Position A', fixed_total: 5 }],
                    spot_total: 0,
                    existing: {
                        shift_id: 9,
                        fixed_total: 7,
                        temp_total: 0,
                        assigned_count: 3,
                        unassigned_position_count: 1,
                        positions: [
                            { workplace_position_id: 1, name: 'Position A', fixed_total: 5, assigned_count: 2 },
                        ],
                    },
                }),
            ]),
        );

        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        expect(within(row).getByText('(Assigned: 2)')).toBeInTheDocument();
        expect(within(row).getAllByText('Without a position')).toHaveLength(2);
        expect(within(row).getByText('(Assigned: 3)')).toBeInTheDocument();
    });

    it('lines the file up against the system with the same rows on both sides', () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'diff',
                    differences: ['fixed_total', 'positions'],
                    fixed_total: 10,
                    spot_total: 5,
                    positions: [
                        { workplace_position_id: 1, name: 'Position A', fixed_total: 3 },
                        { workplace_position_id: 2, name: 'Position B', fixed_total: 2 },
                    ],
                    existing: {
                        shift_id: 9,
                        fixed_total: 8,
                        temp_total: 0,
                        assigned_count: 0,
                        unassigned_position_count: 0,
                        positions: [
                            { workplace_position_id: 1, name: 'Position A', fixed_total: 5, assigned_count: 0 },
                            { workplace_position_id: 2, name: 'Position B', fixed_total: 2, assigned_count: 0 },
                        ],
                    },
                }),
            ]),
        );

        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        const cells = within(row).getAllByRole('cell');

        expect(readComparisonLines(cells[2])).toEqual([
            ['Fixed total', '10'],
            ['Position A', '3'],
            ['Position B', '2'],
            ['spot(Unassigned)', '5'],
        ]);
        expect(readComparisonLines(cells[3])).toEqual([
            ['Fixed total', '8'],
            ['Position A', '5'],
            ['Position B', '2'],
            ['spot(Unassigned)', '1'],
        ]);
    });

    it('says the day is not in the system yet when there is no shift to compare', () => {
        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        expect(within(row).getByText('Not in the system yet')).toBeInTheDocument();
    });

    it('confirms new entries without asking for a decision', async () => {
        confirmMutateMock.mockResolvedValue({ data: { created_count: 1, updated_count: 0 } });

        render(<ShiftOrderImportPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Create shifts' }));

        expect(confirmMutateMock).toHaveBeenCalledWith([]);
        expect(showSuccessMock).toHaveBeenCalled();
        expect(pushMock).toHaveBeenCalledWith('/import-history/7');
    });

    it('blocks the confirmation until every difference has a decision', async () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'diff',
                    differences: ['fixed_total'],
                    existing: {
                        shift_id: 9,
                        fixed_total: 5,
                        temp_total: 0,
                        assigned_count: 2,
                        unassigned_position_count: 0,
                        positions: [
                            { workplace_position_id: 1, name: 'Position A', fixed_total: 3, assigned_count: 2 },
                        ],
                    },
                }),
            ]),
        );
        confirmMutateMock.mockResolvedValue({ data: { created_count: 0, updated_count: 1 } });

        render(<ShiftOrderImportPage />);

        expect(screen.getByRole('button', { name: 'Create shifts' })).toBeDisabled();

        await userEvent.click(screen.getByRole('button', { name: 'File' }));
        await userEvent.click(screen.getByRole('button', { name: 'Create shifts' }));

        expect(confirmMutateMock).toHaveBeenCalledWith([{ workplace_shift_id: 4, date: '2026-10-01', source: 'file' }]);
    });

    it('keeps the decision hint in place so the row does not resize when a side is picked', async () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'diff',
                    differences: ['fixed_total'],
                    existing: {
                        shift_id: 9,
                        fixed_total: 5,
                        temp_total: 0,
                        assigned_count: 0,
                        unassigned_position_count: 0,
                        positions: [],
                    },
                }),
            ]),
        );

        render(<ShiftOrderImportPage />);

        const row = screen.getByText('2026-10-01').closest('tr') as HTMLElement;
        const cell = within(row).getAllByRole('cell')[4];

        expect(within(cell).getByText('Not chosen yet')).toBeInTheDocument();

        await userEvent.click(within(cell).getByRole('button', { name: 'System' }));

        expect(within(cell).queryByText('Not chosen yet')).not.toBeInTheDocument();
        expect(within(cell).getByText('Keeping the system')).toBeInTheDocument();
        expect(within(cell).getByRole('button', { name: 'System' })).toHaveAttribute('aria-pressed', 'true');

        await userEvent.click(within(cell).getByRole('button', { name: 'File' }));

        expect(within(cell).getByText('Using the file')).toBeInTheDocument();
        expect(within(cell).getByRole('button', { name: 'File' })).toHaveAttribute('aria-pressed', 'true');
    });

    it('applies the bulk keep-system choice to every difference', async () => {
        previewQueryMock.mockReturnValue(
            buildPreview([
                buildDay({
                    status: 'diff',
                    differences: ['fixed_total'],
                    existing: {
                        shift_id: 9,
                        fixed_total: 5,
                        temp_total: 0,
                        assigned_count: 0,
                        unassigned_position_count: 0,
                        positions: [],
                    },
                }),
            ]),
        );
        confirmMutateMock.mockResolvedValue({ data: { created_count: 0, updated_count: 0 } });

        render(<ShiftOrderImportPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Keep the system for all' }));
        await userEvent.click(screen.getByRole('button', { name: 'Create shifts' }));

        expect(confirmMutateMock).toHaveBeenCalledWith([
            { workplace_shift_id: 4, date: '2026-10-01', source: 'system' },
        ]);
    });

    it('recalculates the plan when the month is changed', async () => {
        updateMonthMutateMock.mockResolvedValue({ data: {} });

        render(<ShiftOrderImportPage />);
        await userEvent.type(screen.getByLabelText('month-input'), '2026-11-01');

        expect(updateMonthMutateMock).toHaveBeenCalledWith('2026-11');
        expect(refetchMock).toHaveBeenCalled();
    });

    it('shows the server errors raised while confirming', async () => {
        confirmMutateMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'SHIFT_ORDER_FILE_HAS_ERRORS',
                data: { errors: [{ code: 'no_shift_order' }] },
            }),
        );

        render(<ShiftOrderImportPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Create shifts' }));

        expect(screen.getByRole('alert')).toHaveTextContent('The file has no shift order to import');
    });

    it('reloads the preview when the system data changed while the page was open', async () => {
        confirmMutateMock.mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'SHIFT_ORDER_FILE_HAS_ERRORS',
                data: {
                    errors: [
                        {
                            code: 'decision_required',
                            workplace_name: 'Musashino',
                            shift_label: '22:00〜30:00',
                            date: '2026-10-01',
                        },
                    ],
                },
            }),
        );

        render(<ShiftOrderImportPage />);
        await userEvent.click(screen.getByRole('button', { name: 'Create shifts' }));

        expect(refetchMock).toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Choose a value for Musashino - 22:00〜30:00 on 2026-10-01',
        );
    });

    it('waits for the permissions to load instead of claiming access is denied', () => {
        useAuthLoadedMock.mockReturnValue(false);
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: false });

        render(<ShiftOrderImportPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('says the import was already confirmed instead of spinning forever', () => {
        previewQueryMock.mockReturnValue({
            data: undefined,
            isLoading: false,
            error: new ApiError({
                status: 400,
                message: 'SHIFT_ORDER_BATCH_NOT_PENDING',
                data: null as never,
            }),
            refetch: refetchMock,
        });

        render(<ShiftOrderImportPage />);

        expect(screen.getByRole('alert')).toHaveTextContent('This import has already been confirmed');
        expect(screen.queryByTestId('loader')).not.toBeInTheDocument();
    });

    it('says the batch is missing when the preview cannot be loaded', () => {
        previewQueryMock.mockReturnValue({
            data: undefined,
            isLoading: false,
            error: new ApiError({ status: 404, message: 'E_NOT_FOUND', data: null as never }),
            refetch: refetchMock,
        });

        render(<ShiftOrderImportPage />);

        expect(screen.getByRole('alert')).toHaveTextContent('Import batch not found');
        expect(screen.queryByTestId('loader')).not.toBeInTheDocument();
    });

    it('refuses to render without the import order permission', () => {
        usePermissionsMock.mockReturnValue({ SHIFT_IMPORT_ORDER: false });

        render(<ShiftOrderImportPage />);

        expect(screen.getByRole('alert')).toHaveTextContent('You do not have permission to access this page');
    });
});
