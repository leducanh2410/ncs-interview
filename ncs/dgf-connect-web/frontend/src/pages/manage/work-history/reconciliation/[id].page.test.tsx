import React from 'react';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import IndexPage from '@/pages/manage/work-history/reconciliation/[id].page';

const {
    useGetReconciliationBatchQueryMock,
    usePatchReconciliationRowMutationMock,
    useBulkDecideReconciliationMutationMock,
    useConfirmReconciliationMutationMock,
    useExportReconciliationMutationMock,
    usePermissionsMock,
    searchReconciliationUsersMock,
    downloadReconciliationOriginalMock,
} = vi.hoisted(() => ({
    useGetReconciliationBatchQueryMock: vi.fn(),
    usePatchReconciliationRowMutationMock: vi.fn(),
    useBulkDecideReconciliationMutationMock: vi.fn(),
    useConfirmReconciliationMutationMock: vi.fn(),
    useExportReconciliationMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    searchReconciliationUsersMock: vi.fn(),
    downloadReconciliationOriginalMock: vi.fn(),
}));

vi.mock('next/link', () => ({
    default: ({ children }: { children: React.ReactNode }) => React.createElement('a', null, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => ({ query: { id: '1' } }),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { 'aria-label': ariaLabel }, children),
    Anchor: ({ children, onClick }: any) => React.createElement('a', { onClick }, children),
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { onClick, disabled, 'data-loading': loading ? 'true' : undefined }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Popover: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Target: ({ children }: any) => React.createElement('div', null, children),
        Dropdown: ({ children }: any) => React.createElement('div', null, children),
    }),
    Modal: ({ opened, children }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'map-modal' }, children) : null,
    Paper: React.forwardRef(({ children, style, 'data-testid': testId }: any, ref: any) =>
        React.createElement('div', { ref, style, 'data-testid': testId }, children),
    ),
    Radio: Object.assign(
        ({ value, label, disabled }: any) =>
            React.createElement('label', null, React.createElement('input', { type: 'radio', value, disabled }), label),
        {
            Group: ({ children, onChange }: any) => React.createElement('div', { onChange }, children),
        },
    ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ value, onChange, label }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { value, onChange, 'data-testid': 'search-input' }),
        ),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: () => usePermissionsMock(),
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user, isLink = true }: any) =>
        React.createElement('div', {
            'data-testid': 'avatar',
            'data-user-id': user?.id,
            'data-link': String(isLink),
        }),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: vi.fn(), showSuccess: vi.fn() },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parse: (value: string) => ({
            format: (fmt: string) => (fmt === 'HH:mm' ? value.slice(11, 16) : value.slice(0, 10)),
        }),
        parseAndFormat: (value: string) => value,
    },
    DATE_STORE_FORMAT_WITH_TIME: 'YYYY-MM-DD HH:mm:ss',
}));

vi.mock('@/pages/manage/work-history/reconciliation/api', () => ({
    useGetReconciliationBatchQuery: () => useGetReconciliationBatchQueryMock(),
    usePatchReconciliationRowMutation: () => usePatchReconciliationRowMutationMock(),
    useBulkDecideReconciliationMutation: () => useBulkDecideReconciliationMutationMock(),
    useConfirmReconciliationMutation: () => useConfirmReconciliationMutationMock(),
    useExportReconciliationMutation: () => useExportReconciliationMutationMock(),
    searchReconciliationUsers: (...args: unknown[]) => searchReconciliationUsersMock(...args),
    downloadReconciliationOriginal: (...args: unknown[]) => downloadReconciliationOriginalMock(...args),
}));

const differingRow = {
    id: 1,
    external_name: 'TEST USER',
    work_date: '2026-08-25',
    external_check_in: '2026-08-25 20:19:00',
    external_check_out: '2026-08-26 06:03:00',
    external_break_minutes: 60,
    match_status: 'exact',
    match_method: 'exact_kana',
    skipped: false,
    user: { id: 9, staff_no: 'S1', name: 'Test', name_kana: 'テスト', profile_picture: null },
    check_in_status: 'single',
    check_in_id: 4,
    workplace: { id: 1, name: 'WP' },
    compare_status: 'differs',
    has_pending_edit_request: false,
    dgf_check_in: '2026-08-25 20:15:00',
    dgf_check_out: '2026-08-26 06:00:00',
    dgf_break_minutes: 60,
    decisions: { check_in_time: null, check_out_time: null, break_minutes: 'dgf' },
};

describe('reconciliation detail', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        Element.prototype.scrollIntoView = vi.fn();
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true });
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync: vi.fn() });
        useBulkDecideReconciliationMutationMock.mockReturnValue({ mutate: vi.fn() });
        useConfirmReconciliationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [differingRow],
                },
            },
        });
    });

    it('disables confirm while unresolved', () => {
        render(React.createElement(IndexPage));
        expect(screen.getByText('Confirm reconciliation')).toBeDisabled();
        expect(screen.getByText(/20:19/)).toBeInTheDocument();
        expect(screen.getAllByText(/\{\{time\}\} \(next day\)/).length).toBeGreaterThan(0);
        // "Differs" also labels the row filter button, so assert presence rather than uniqueness.
        expect(screen.getAllByText('Differs').length).toBeGreaterThan(0);
    });

    it('shows export when confirmed and read-only', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText('Export attendance file')).toBeInTheDocument();
        expect(screen.queryByText('Confirm reconciliation')).not.toBeInTheDocument();
    });

    it('bulk accept triggers mutation', () => {
        const mutate = vi.fn();
        useBulkDecideReconciliationMutationMock.mockReturnValue({ mutate });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Accept remaining {{source}}'));
        expect(mutate).toHaveBeenCalledWith('amazon', expect.objectContaining({ onSuccess: expect.any(Function) }));
    });

    it('labels the external side after the batch source and shows the file employee ID', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    source: 'yamato_staff_data',
                    original_filename: 'yamato.xlsx',
                    rows: [{ ...differingRow, external_staff_no: '25608', match_method: 'staff_no' }],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText(/Yamato timesheet/)).toBeInTheDocument();
        expect(screen.getAllByText(/Yamato:/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/Amazon:/)).not.toBeInTheDocument();
        expect(screen.getByText(/25608/)).toBeInTheDocument();
    });

    it('lets the admin pick a check-in when multiple exist', async () => {
        const mutateAsync = vi.fn();
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_status: 'multiple',
                            check_in_id: null,
                            compare_status: 'not_comparable',
                            check_ins_options: [
                                { id: 4, workplace_id: 1, workplace_name: 'WP', shift_time: '21:00-06:00' },
                                { id: 5, workplace_id: 1, workplace_name: 'WP', shift_time: '08:00-12:00' },
                            ],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('WP 08:00-12:00'));
        expect(mutateAsync).toHaveBeenCalledWith({ rowId: 1, data: { check_in_id: 5 } });
        expect(screen.getByText('Multiple check-ins')).toBeInTheDocument();
        // patchRow awaits mutateAsync() before clearing problemRowIds; flush that
        // microtask inside act() so the state update isn't reported as unwrapped.
        await waitFor(() => expect(mutateAsync).toHaveBeenCalledTimes(1));
    });

    it('opens map modal search field', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        { ...differingRow, user: null, match_status: 'unmatched', compare_status: 'not_comparable' },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Map user'));
        expect(screen.getByTestId('map-modal')).toBeInTheDocument();
        expect(screen.getByTestId('search-input')).toBeInTheDocument();
        expect(screen.getByText('No match')).toBeInTheDocument();
    });

    it('shows candidate avatars in the map modal', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            user: null,
                            match_status: 'similar',
                            compare_status: 'not_comparable',
                            candidates: [
                                {
                                    id: 11,
                                    staff_no: 'S2',
                                    name: 'Candidate',
                                    name_kana: 'コウホ',
                                    profile_picture: 'http://example/a.png',
                                    check_ins: [
                                        {
                                            id: 9,
                                            workplace_id: 1,
                                            workplace_name: 'WP',
                                            shift_time: '21:00-06:00',
                                            check_in_time: '2026-08-25 20:15:00',
                                            check_out_time: '2026-08-26 06:00:00',
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Map user'));
        expect(screen.getByTestId('map-modal')).toBeInTheDocument();
        expect(screen.getByTestId('avatar')).toBeInTheDocument();
        expect(screen.getByText(/Candidate/)).toBeInTheDocument();
        expect(screen.getByText(/WP 21:00-06:00/)).toBeInTheDocument();
    });

    // Row-level status badge shown above each row (see getStatusInfo in [id].page.tsx) — one
    // branch per match/compare/skip state, since a wrong branch order would silently mislabel
    // a row the admin relies on to decide how to resolve it.
    describe.each([
        ['skipped row', { skipped: true }, 'Skipped'],
        ['similar-name row', { match_status: 'similar', user: null }, 'Similar name'],
        ['ambiguous-name row', { match_status: 'ambiguous', user: null }, 'Ambiguous name'],
        ['duplicate-external row', { match_status: 'duplicate_external', user: null }, 'Duplicate row'],
        ['no-DGF-attendance row', { check_in_status: 'none', check_in_id: null }, 'No DGF attendance'],
        ['fully matched row', { compare_status: 'matched' }, 'Matched'],
    ])('status badge for %s', (_name, overrides, expectedLabel) => {
        it(`shows "${expectedLabel}"`, () => {
            useGetReconciliationBatchQueryMock.mockReturnValue({
                isPending: false,
                data: {
                    data: {
                        id: 1,
                        status: 'draft',
                        original_filename: 'amazon.xlsx',
                        rows: [{ ...differingRow, ...overrides }],
                    },
                },
            });
            render(React.createElement(IndexPage));
            // Some labels (e.g. "Skipped", "Matched") are reused elsewhere on the row (the
            // skip button, per-field decision text), so assert presence rather than uniqueness.
            expect(screen.getAllByText(expectedLabel).length).toBeGreaterThan(0);
        });
    });

    it('flags rows sharing a check-in and disables confirm', () => {
        const resolvedRow = {
            ...differingRow,
            compare_status: 'matched',
            decisions: { check_in_time: 'dgf', check_out_time: 'dgf', break_minutes: 'dgf' },
        };
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        { ...resolvedRow, id: 1 },
                        { ...resolvedRow, id: 2 },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText('Confirm reconciliation')).toBeDisabled();
        expect(screen.getAllByText('Duplicate check-in')).toHaveLength(2);
    });

    it('surfaces the duplicate-check-in error and highlights the reported rows', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'RECONCILIATION_DUPLICATE_CHECK_IN',
                data: { row_ids: [1] },
            }),
        );
        useConfirmReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        // The client's own duplicate check only catches this once the batch is
        // refetched; here it still looks fully resolved, matching the real bug
        // where the server finds the conflict on Confirm before the UI does.
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            compare_status: 'matched',
                            decisions: { check_in_time: 'dgf', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Confirm reconciliation'));

        await waitFor(() => expect(mutateAsync).toHaveBeenCalled());
        await waitFor(() => expect(Element.prototype.scrollIntoView).toHaveBeenCalled());
        const NotificationHelper = (await import('@/helpers/notification.helper')).default;
        expect(NotificationHelper.showError).toHaveBeenCalledWith(
            'Two or more rows are mapped to the same DGF check-in',
        );
    });

    it('switches back to the "all" filter so a flagged row hidden by the active tab becomes visible', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'RECONCILIATION_DECISIONS_INCOMPLETE',
                data: { unfinished_row_ids: [2] },
            }),
        );
        useConfirmReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            id: 1,
                            external_name: 'MATCHED ROW',
                            check_in_id: 10,
                            compare_status: 'matched',
                        },
                        {
                            ...differingRow,
                            id: 2,
                            external_name: 'FLAGGED ROW',
                            check_in_id: 11,
                            compare_status: 'differs',
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        // Switch to the "Matched" tab, which hides row 2 — the row the server
        // will report as unfinished on Confirm.
        fireEvent.click(screen.getByRole('button', { name: 'Matched' }));
        expect(screen.queryByText('FLAGGED ROW')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Confirm reconciliation'));

        // The row appearing and the scroll are two separate commits: the effect that
        // scrolls waits for the ref from the render that re-added the row, so asserting
        // the scroll outside the waitFor raced it and failed on a loaded machine.
        await waitFor(() => expect(screen.getByText('FLAGGED ROW')).toBeInTheDocument());
        await waitFor(() => expect(Element.prototype.scrollIntoView).toHaveBeenCalled());
    });

    it('clears the stale highlight once the admin edits a row instead of retrying Confirm', async () => {
        const confirmMutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 422,
                message: 'RECONCILIATION_DUPLICATE_CHECK_IN',
                data: { row_ids: [1] },
            }),
        );
        useConfirmReconciliationMutationMock.mockReturnValue({ mutateAsync: confirmMutateAsync, isPending: false });
        const patchMutateAsync = vi.fn().mockResolvedValue({ data: {} });
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync: patchMutateAsync });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            compare_status: 'matched',
                            decisions: { check_in_time: 'dgf', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('Confirm reconciliation'));
        await waitFor(() =>
            expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe('var(--mantine-color-red-6)'),
        );

        // The admin edits the row directly (e.g. Skip) rather than retrying
        // Confirm — since we can't know client-side whether that actually
        // resolved the server-reported conflict, the highlight must drop
        // rather than keep pointing at a row that may already be fine.
        fireEvent.click(screen.getByText('Skip'));
        await waitFor(() => expect(patchMutateAsync).toHaveBeenCalled());
        await waitFor(() => expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe(''));
    });

    it('shows no status badge once every field is resolved and comparable', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [{ ...differingRow, compare_status: 'not_comparable' }],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.queryByLabelText('What does this mean?')).not.toBeInTheDocument();
    });

    it('enables confirm when live DGF matches Amazon even if decisions are still null', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            dgf_check_in: differingRow.external_check_in,
                            dgf_check_out: differingRow.external_check_out,
                            dgf_break_minutes: differingRow.external_break_minutes,
                            decisions: { check_in_time: null, check_out_time: null, break_minutes: null },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText('Confirm reconciliation')).not.toBeDisabled();
    });

    it('shows candidate check-in workplace and time in the map modal', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            user: null,
                            match_status: 'ambiguous',
                            compare_status: 'not_comparable',
                            candidates: [
                                {
                                    id: 9,
                                    staff_no: 'S1',
                                    name: 'Uma Tapa',
                                    name_kana: 'タパ ウマ',
                                    profile_picture: null,
                                    check_ins: [
                                        {
                                            id: 4,
                                            workplace_id: 1,
                                            workplace_name: 'WP',
                                            shift_time: '21:00-06:00',
                                            check_in_time: '2026-08-25 20:15:00',
                                            check_out_time: '2026-08-26 06:00:00',
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Map user'));
        expect(screen.getByText(/WP 21:00-06:00/)).toBeInTheDocument();
        expect(screen.getByText(/20:15/)).toBeInTheDocument();
    });

    // Requirement 11.3: the detail screen is where a disputed reconciliation is
    // re-checked, so it must name who did what and when, and hand back the
    // uploaded file itself.
    it('shows the batch audit trail and downloads the original file', async () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 7,
                    source: 'amazon_kadou',
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    uploaded_by: { id: 1, name: 'Uploader' },
                    created_at: '2026-08-25 09:00:00',
                    confirmed_by: { id: 2, name: 'Confirmer' },
                    confirmed_at: '2026-08-26 09:00:00',
                    exported_by: { id: 3, name: 'Exporter' },
                    exported_at: '2026-08-27 09:00:00',
                    rows: [differingRow],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText(/· 2026-08-25 09:00:00/)).toBeInTheDocument();
        expect(screen.getAllByTestId('avatar').some((element) => element.getAttribute('data-user-id') === '1')).toBe(
            true,
        );
        expect(screen.getByText(/Confirmer · 2026-08-26 09:00:00/)).toBeInTheDocument();
        expect(screen.getByText(/Exporter · 2026-08-27 09:00:00/)).toBeInTheDocument();

        fireEvent.click(screen.getByText('Download original file'));
        await waitFor(() => expect(downloadReconciliationOriginalMock).toHaveBeenCalledWith(7, 'amazon.xlsx'));
    });

    it('shows a dash for audit milestones the batch has not reached yet', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    source: 'amazon_kadou',
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    uploaded_by: { id: 1, name: 'Uploader' },
                    created_at: '2026-08-25 09:00:00',
                    confirmed_by: null,
                    confirmed_at: null,
                    exported_by: null,
                    exported_at: null,
                    rows: [differingRow],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText(/^Confirmed by: -$/)).toBeInTheDocument();
        expect(screen.getByText(/^Exported by: -$/)).toBeInTheDocument();
    });

    // A search that returns nothing used to fall back to the row's auto-suggested
    // candidates, presenting them as if they matched the typed keyword.
    it('reports an empty search instead of falling back to the row candidates', async () => {
        searchReconciliationUsersMock.mockResolvedValue({ data: [] });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            user: null,
                            match_status: 'similar',
                            compare_status: 'not_comparable',
                            candidates: [
                                {
                                    id: 11,
                                    staff_no: 'S2',
                                    name: 'Candidate',
                                    name_kana: 'コウホ',
                                    profile_picture: null,
                                },
                            ],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        fireEvent.click(screen.getByText('Map user'));
        expect(screen.getByText(/Candidate/)).toBeInTheDocument();

        fireEvent.change(screen.getByTestId('search-input'), { target: { value: 'nobody' } });
        fireEvent.click(screen.getByRole('button', { name: 'Search' }));

        await waitFor(() => expect(screen.getByText('No users found')).toBeInTheDocument());
        expect(screen.queryByText(/Candidate/)).not.toBeInTheDocument();
    });

    it('flags a row whose DGF attendance appeared only after the upload', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_id: null,
                            check_in_status: 'single',
                            compare_status: 'not_comparable',
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByText('DGF attendance added after upload')).toBeInTheDocument();
        expect(
            screen.getByText(
                'DGF attendance for this employee was entered after this file was uploaded. Confirm the batch to link it and compare the values.',
            ),
        ).toBeInTheDocument();

        // The same row must be reachable from a filter tab, not just "All", or
        // an admin working through the tabs never sees it.
        fireEvent.click(screen.getByRole('button', { name: 'No DGF attendance' }));
        expect(screen.getByText('TEST USER')).toBeInTheDocument();
    });

    // A confirmed batch is otherwise read-only, but a row whose DGF check-in was
    // deleted afterwards can never be exported or repaired and blocks the whole
    // workbook, so skipping it is the one write left.
    it('offers to skip a confirmed row whose DGF check-in was deleted', async () => {
        const mutateAsync = vi.fn().mockResolvedValue({ data: {} });
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            // Confirmed batches serve dgf_* from the snapshot, so
                            // values here with no check_in_id mean the row was
                            // linked and the check-in has since been deleted.
                            // `can_skip` is the server's own answer to whether
                            // that actually blocks the export.
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'not_comparable',
                            can_skip: true,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('DGF attendance removed after confirmation')).toBeInTheDocument();
        expect(
            screen.getByText(
                'The DGF check-in this row was confirmed against has been deleted, so it can no longer be exported. Skip it to export the remaining rows.',
            ),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByText('Skip and export the rest'));
        await waitFor(() => expect(mutateAsync).toHaveBeenCalledWith({ rowId: 1, data: { skipped: true } }));
    });

    it('does not offer the skip escape hatch on a row that never had a check-in', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'not_comparable',
                            dgf_check_in: null,
                            dgf_check_out: null,
                            dgf_break_minutes: null,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.queryByText('Skip and export the rest')).not.toBeInTheDocument();
        expect(screen.getByText('Never linked to DGF attendance')).toBeInTheDocument();
    });

    // The batch was confirmed while the employee had no attendance that day, so
    // the row was frozen unlinked with an empty snapshot. DGF attendance typed
    // in afterwards makes check_in_status `single` again — but there is no
    // Confirm button left on this screen to act on the advice the draft badge
    // gives, and the row will never be exported.
    it('does not tell a confirmed batch to press Confirm when attendance arrives late', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_id: null,
                            check_in_status: 'single',
                            compare_status: 'not_comparable',
                            dgf_check_in: null,
                            dgf_check_out: null,
                            dgf_break_minutes: null,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('Never linked to DGF attendance')).toBeInTheDocument();
        expect(screen.queryByText('DGF attendance added after upload')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'This row had no DGF check-in when the batch was confirmed, so it was never linked to one and is not part of the export.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Confirm')).not.toBeInTheDocument();
        expect(screen.queryByText('Skip and export the rest')).not.toBeInTheDocument();
    });

    // Export applies "would this row change a DGF value?" before it ever looks
    // at the check-in, so a row that changes nothing is passed over whether or
    // not its check-in survived. Offering to skip it would send the admin after
    // a row that blocks nothing — and the PATCH would be refused. The fixture
    // takes the harder reachable form of it: Amazon *was* accepted, on a field
    // whose DGF value already agreed, so the copy must talk about the outcome
    // rather than claim no Amazon value was chosen.
    it('does not offer to skip a row whose lost check-in was never blocking the export', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'matched',
                            dgf_check_in: differingRow.external_check_in,
                            dgf_check_out: differingRow.external_check_out,
                            dgf_break_minutes: differingRow.external_break_minutes,
                            decisions: { check_in_time: 'amazon', check_out_time: 'amazon', break_minutes: 'amazon' },
                            can_skip: false,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('DGF attendance removed after confirmation')).toBeInTheDocument();
        expect(
            screen.getByText(
                'The DGF check-in this row was confirmed against has been deleted. Nothing on this row would have changed a DGF value, so it was never part of the export and nothing is blocked.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText('Skip and export the rest')).not.toBeInTheDocument();
    });

    it('highlights the row the server reports as stale when the export is blocked', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'RECONCILIATION_STALE',
                data: { row_ids: [1] },
            }),
        );
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('Export attendance file'));

        await waitFor(() =>
            expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe('var(--mantine-color-red-6)'),
        );
        const NotificationHelper = (await import('@/helpers/notification.helper')).default;
        expect(NotificationHelper.showError).toHaveBeenCalledWith(
            'DGF attendance has changed since this batch was confirmed',
        );
    });

    it('switches back to "all" so the stale row is rendered before scrolling to it', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'RECONCILIATION_STALE',
                data: { row_ids: [2] },
            }),
        );
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        { ...differingRow, id: 1, external_name: 'MATCHED ROW', compare_status: 'matched' },
                        {
                            ...differingRow,
                            id: 2,
                            external_name: 'STALE ROW',
                            check_in_id: null,
                            check_in_status: 'single',
                            compare_status: 'not_comparable',
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        // A row that lost its check-in is not in "Matched", so leaving the tab
        // alone would leave it unrendered and the scroll with no ref to aim at.
        fireEvent.click(screen.getByRole('button', { name: 'Matched' }));
        expect(screen.queryByText('STALE ROW')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Export attendance file'));

        await waitFor(() => expect(screen.getByText('STALE ROW')).toBeInTheDocument());
        expect(Element.prototype.scrollIntoView).toHaveBeenCalled();
        expect(screen.getByTestId('reconciliation-row-2').style.borderColor).toBe('var(--mantine-color-red-6)');
    });

    it('drops the stale highlight once the export succeeds', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValueOnce(
                new ApiError({ status: 409, message: 'RECONCILIATION_STALE', data: { row_ids: [1] } }),
            )
            .mockResolvedValueOnce(undefined);
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('Export attendance file'));
        await waitFor(() =>
            expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe('var(--mantine-color-red-6)'),
        );

        // DGF is put back and the export goes through — the red border from the
        // previous attempt must not survive it.
        fireEvent.click(screen.getByText('Export attendance file'));
        await waitFor(() => expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe(''));
    });

    // Export names every stale row in one response precisely so the admin does
    // not have to re-run it after each fix. Skipping one of them must therefore
    // leave the others marked — the second row here still has its check-in, only
    // its times moved, so it carries no badge of its own and the red border is
    // the only thing pointing at it.
    it('keeps the other stale rows highlighted after one of them is skipped', async () => {
        const patch = vi.fn().mockResolvedValue({ data: {} });
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync: patch });
        const exportMutate = vi
            .fn()
            .mockRejectedValue(
                new ApiError({ status: 409, message: 'RECONCILIATION_STALE', data: { row_ids: [1, 2] } }),
            );
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync: exportMutate, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            id: 1,
                            external_name: 'ORPHAN ROW',
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'not_comparable',
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                            can_skip: true,
                        },
                        {
                            ...differingRow,
                            id: 2,
                            external_name: 'DRIFTED ROW',
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('Export attendance file'));
        await waitFor(() =>
            expect(screen.getByTestId('reconciliation-row-2').style.borderColor).toBe('var(--mantine-color-red-6)'),
        );

        fireEvent.click(screen.getByText('Skip and export the rest'));
        await waitFor(() => expect(patch).toHaveBeenCalledWith({ rowId: 1, data: { skipped: true } }));

        await waitFor(() => expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe(''));
        expect(screen.getByTestId('reconciliation-row-2').style.borderColor).toBe('var(--mantine-color-red-6)');
    });

    // `row.skipped` only flips after the PATCH is invalidated and refetched, so
    // the button stays enabled through the whole round trip unless the pending
    // state disables it — long enough for an impatient double click to send the
    // same skip twice.
    it('locks the skip button while its request is in flight', () => {
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: true });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'not_comparable',
                            can_skip: true,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByRole('button', { name: 'Skip and export the rest' })).toBeDisabled();
    });
    // The scroll exists to carry the admin to a freshly reported problem row, so
    // it must fire on a server verdict and nothing else. Resolving one of several
    // reported rows shrinks the highlight list, and while that list also drove
    // the scroll every skip yanked the screen back to the first flagged row —
    // worst exactly when Export reported many rows and the admin was working
    // down them.
    it('does not scroll back to the first stale row when another reported row is skipped', async () => {
        const patch = vi.fn().mockResolvedValue({ data: {} });
        usePatchReconciliationRowMutationMock.mockReturnValue({ mutateAsync: patch, isPending: false });
        const exportMutate = vi
            .fn()
            .mockRejectedValue(
                new ApiError({ status: 409, message: 'RECONCILIATION_STALE', data: { row_ids: [1, 2] } }),
            );
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync: exportMutate, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            id: 1,
                            external_name: 'DRIFTED ROW',
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                        {
                            ...differingRow,
                            id: 2,
                            external_name: 'ORPHAN ROW',
                            check_in_id: null,
                            check_in_status: 'none',
                            compare_status: 'not_comparable',
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                            can_skip: true,
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        fireEvent.click(screen.getByText('Export attendance file'));
        await waitFor(() => expect(Element.prototype.scrollIntoView).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByText('Skip and export the rest'));
        await waitFor(() => expect(patch).toHaveBeenCalledWith({ rowId: 2, data: { skipped: true } }));

        // Row 1 keeps its border — the highlight list was updated — but the
        // viewport stays where the admin left it.
        await waitFor(() => expect(screen.getByTestId('reconciliation-row-2').style.borderColor).toBe(''));
        expect(screen.getByTestId('reconciliation-row-1').style.borderColor).toBe('var(--mantine-color-red-6)');
        expect(Element.prototype.scrollIntoView).toHaveBeenCalledTimes(1);
    });

    // A stale row that still has its check-in only moved in time, so it falls to
    // the `differs` branch, whose Draft wording tells the admin to pick a side on
    // radios that are disabled. It is the row actually blocking Export and it has
    // no skip button, so the badge is the only place that can name the way out.
    it('explains that a stale confirmed row needs its DGF attendance restored', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValue(new ApiError({ status: 409, message: 'RECONCILIATION_STALE', data: { row_ids: [1] } }));
        useExportReconciliationMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        // Before Export nothing is known to be stale, so the row is just a
        // confirmed differing row.
        expect(screen.queryByText('DGF attendance changed after confirmation')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Export attendance file'));

        await waitFor(() => expect(screen.getByText('DGF attendance changed after confirmation')).toBeInTheDocument());
        expect(
            screen.getByText(
                'DGF attendance for this row changed after the batch was confirmed, so the DGF values shown here are the ones recorded at that time. Restore them in Work history to unblock the export.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Choose Amazon or DGF for each differing field below/)).not.toBeInTheDocument();
    });

    // Same class of bug as the badge above: on a confirmed batch the radios are
    // disabled, so the Draft instruction describes an action the admin cannot
    // take.
    it('describes the recorded choice instead of asking for one on a confirmed differing row', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            decisions: { check_in_time: 'amazon', check_out_time: 'dgf', break_minutes: 'dgf' },
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(
            screen.getByText(
                'At least one field differed between {{source}} and DGF. The choice recorded when the batch was confirmed is shown below and can no longer be changed.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/Choose Amazon or DGF for each differing field below/)).not.toBeInTheDocument();
    });

    // One mutation object serves every row, so an unscoped `isPending` spins the
    // skip button of every other reported row too — on the very screen whose job
    // is telling the admin which row still needs them.
    it('spins only the skip button of the row being saved', () => {
        usePatchReconciliationRowMutationMock.mockReturnValue({
            mutateAsync: vi.fn(),
            isPending: true,
            variables: { rowId: 1, data: { skipped: true } },
        });
        const orphan = {
            ...differingRow,
            check_in_id: null,
            check_in_status: 'none',
            compare_status: 'not_comparable',
            can_skip: true,
        };
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        { ...orphan, id: 1, external_name: 'SAVING ROW' },
                        { ...orphan, id: 2, external_name: 'OTHER ROW' },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        const saving = within(screen.getByTestId('reconciliation-row-1')).getByRole('button', {
            name: 'Skip and export the rest',
        });
        const other = within(screen.getByTestId('reconciliation-row-2')).getByRole('button', {
            name: 'Skip and export the rest',
        });
        expect(saving).toHaveAttribute('data-loading', 'true');
        expect(other).not.toHaveAttribute('data-loading');
        // Parallel skips stay blocked either way.
        expect(other).toBeDisabled();
    });

    it('shows the Amazon vs DGF hours breakdown and flags the differing fields', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        {
                            ...differingRow,
                            external_summary: {
                                work_minutes: 480,
                                work_minutes_normal: 60,
                                work_minutes_overtime: 0,
                                work_minutes_night: 420,
                                work_minutes_night_overtime: null,
                            },
                            dgf_summary: {
                                work_minutes: 480,
                                work_minutes_normal: 120,
                                work_minutes_overtime: 0,
                                work_minutes_night: 360,
                                work_minutes_night_overtime: 0,
                            },
                            summary_differs: ['work_minutes_normal', 'work_minutes_night'],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('Paid check-in')).toBeInTheDocument();
        expect(screen.getAllByText('Hours breakdown differs')).toHaveLength(2);
        const summary = within(screen.getByTestId('reconciliation-summary-1'));
        expect(summary.getByText('Total work hours')).toBeInTheDocument();
        expect(summary.getByText('Regular hours')).toBeInTheDocument();
        expect(summary.getByText('Night overtime hours')).toBeInTheDocument();
        expect(summary.getAllByText(/8:00/)).toHaveLength(2);
        expect(summary.getByText(/1:00/)).toBeInTheDocument();
        expect(summary.getByText(/2:00/)).toBeInTheDocument();
        expect(summary.getAllByText('Matched')).toHaveLength(2);
        expect(summary.getAllByText(/-/)).toHaveLength(1);
    });

    it('labels a legacy confirmed row with punch times instead of paid times', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [{ ...differingRow, time_basis: 'punch', external_summary: null, dgf_summary: null }],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('Check-in')).toBeInTheDocument();
        expect(screen.getByText('Check-out')).toBeInTheDocument();
        expect(screen.queryByText('Paid check-in')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                'This batch was confirmed before reconciliation switched to paid time. Its check-in and check-out columns compare punch times, not paid times.',
            ),
        ).toBeInTheDocument();
    });

    it('does not show the legacy punch notice for rows compared on paid time', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'confirmed',
                    original_filename: 'amazon.xlsx',
                    rows: [{ ...differingRow, time_basis: 'paid' }],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getByText('Paid check-in')).toBeInTheDocument();
        expect(screen.queryByText(/confirmed before reconciliation switched to paid time/)).not.toBeInTheDocument();
    });

    it('hides the hours breakdown badge when Amazon and DGF agree', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [{ ...differingRow, summary_differs: [] }],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(screen.getAllByText('Hours breakdown differs')).toHaveLength(1);
        expect(screen.queryByTestId('reconciliation-summary-1')).not.toBeInTheDocument();
    });

    it('filters to rows whose hours breakdown differs', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [
                        { ...differingRow, id: 1, summary_differs: ['work_minutes_night'] },
                        { ...differingRow, id: 2, summary_differs: [] },
                        {
                            ...differingRow,
                            id: 3,
                            compare_status: 'not_comparable',
                            summary_differs: ['work_minutes'],
                        },
                    ],
                },
            },
        });
        render(React.createElement(IndexPage));
        expect(screen.getByTestId('reconciliation-row-2')).toBeInTheDocument();

        const tab = screen
            .getAllByText('Hours breakdown differs')
            .find((element) => element.tagName === 'BUTTON') as HTMLElement;
        fireEvent.click(tab);

        expect(screen.getByTestId('reconciliation-row-1')).toBeInTheDocument();
        expect(screen.queryByTestId('reconciliation-row-2')).not.toBeInTheDocument();
        expect(screen.queryByTestId('reconciliation-row-3')).not.toBeInTheDocument();
    });

    it('warns when DGF would recalculate the Amazon paid time differently', () => {
        useGetReconciliationBatchQueryMock.mockReturnValue({
            isPending: false,
            data: {
                data: {
                    id: 1,
                    status: 'draft',
                    original_filename: 'amazon.xlsx',
                    rows: [{ ...differingRow, rounding_warnings: { check_in_time: '2026-08-25 21:45:00' } }],
                },
            },
        });
        render(React.createElement(IndexPage));

        expect(
            screen.getAllByText(
                'If {{source}} is chosen, DGF will recalculate this paid time as {{time}} after import because of the shift rounding or overtime settings.',
            ),
        ).toHaveLength(1);
    });

    it('shows no rounding warning when none is reported', () => {
        render(React.createElement(IndexPage));

        expect(
            screen.queryByText(
                'If {{source}} is chosen, DGF will recalculate this paid time as {{time}} after import because of the shift rounding or overtime settings.',
            ),
        ).not.toBeInTheDocument();
    });

    it('links employee avatars only when the viewer can open user profiles', () => {
        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true, USER_LIST: true });
        const { unmount } = render(React.createElement(IndexPage));
        expect(screen.getAllByTestId('avatar').every((element) => element.getAttribute('data-link') === 'true')).toBe(
            true,
        );
        unmount();

        usePermissionsMock.mockReturnValue({ RECONCILIATION_WORK: true, USER_LIST: false });
        render(React.createElement(IndexPage));
        expect(screen.getAllByTestId('avatar').every((element) => element.getAttribute('data-link') === 'false')).toBe(
            true,
        );
    });
});
