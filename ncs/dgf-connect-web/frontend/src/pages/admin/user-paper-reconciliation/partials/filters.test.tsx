import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ReconciliationFilters from '@/pages/admin/user-paper-reconciliation/partials/filters';

const { routerMock, filterOptionsMock } = vi.hoisted(() => ({
    routerMock: {
        query: {} as Record<string, string>,
        pathname: '/admin/user-paper-reconciliation',
        isReady: true,
        push: vi.fn(),
        replace: vi.fn(),
    },
    filterOptionsMock: vi.fn(),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (key: string) => key }) }));

vi.mock('@/helpers/date.helper', () => ({
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
    default: { now: () => 'today', format: () => '2026-09-15' },
}));

vi.mock('@/pages/admin/user-paper-reconciliation/api', () => ({
    useGetReconciliationFilterOptionsQuery: (workplaceId?: string, date?: string) =>
        filterOptionsMock(workplaceId, date),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, valueFormat }: any) =>
        React.createElement('div', { 'data-testid': `date-${label}`, 'data-value-format': valueFormat }, label),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    TextInput: ({ label, value, onChange, onKeyDown }: any) =>
        React.createElement('input', { 'data-testid': `input-${label}`, value, onChange, onKeyDown }),
    Select: ({ label, data, disabled, description }: any) =>
        React.createElement(
            'div',
            { 'data-testid': `select-${label}`, 'data-disabled': String(Boolean(disabled)) },
            React.createElement('span', null, description ?? ''),
            ...(data ?? []).map((option: any) =>
                React.createElement(
                    'span',
                    {
                        'key': option.value,
                        'data-option': option.value,
                        'data-option-disabled': String(Boolean(option.disabled)),
                    },
                    option.label,
                ),
            ),
        ),
}));

const optionState = (selectLabel: string, value: string) =>
    screen
        .getByTestId(`select-${selectLabel}`)
        .querySelector(`[data-option="${value}"]`)
        ?.getAttribute('data-option-disabled');

describe('ReconciliationFilters', () => {
    beforeEach(() => {
        routerMock.query = {};
        routerMock.push.mockClear();
        routerMock.replace.mockClear();
        filterOptionsMock.mockReturnValue({
            data: { data: { workplaces: [{ id: 7, name: 'Factory A' }], workplace_shifts: [] } },
            isFetching: false,
        });
    });

    it("opens on today's roster when the URL carries no date", () => {
        render(<ReconciliationFilters />);

        expect(routerMock.replace).toHaveBeenCalledWith(
            expect.objectContaining({ query: expect.objectContaining({ date: '2026-09-15' }) }),
            undefined,
            { shallow: true },
        );
    });

    it('leaves the date alone when the URL already carries one', () => {
        routerMock.query = { date: '2026-09-20' };

        render(<ReconciliationFilters />);

        // Including a date the admin picked deliberately: today is a default, not an
        // override.
        expect(routerMock.replace).not.toHaveBeenCalled();
    });

    it('shows the picked date in the same format as every other date in the app', () => {
        routerMock.query = { date: '2026-09-20' };

        render(<ReconciliationFilters />);

        // Mantine's own default reads "September 20, 2026", which matches nothing else
        // on the screen — the list columns next to it are formatted with FORMAT_DATE.
        expect(screen.getByTestId('date-Date')).toHaveAttribute('data-value-format', 'FORMAT_DATE');
    });

    it('resets to today rather than to nothing when the filters are cleared', () => {
        routerMock.query = { date: '2026-09-20', workplace_id: '7' };

        render(<ReconciliationFilters />);
        screen.getByText('Clear filters').click();

        expect(routerMock.push).toHaveBeenCalledWith({
            pathname: '/admin/user-paper-reconciliation',
            query: { date: '2026-09-15' },
        });
    });

    it('keeps the shift select disabled until both a workplace and a date are chosen', () => {
        render(<ReconciliationFilters />);
        expect(screen.getByTestId('select-Shift').getAttribute('data-disabled')).toBe('true');

        routerMock.query = { workplace_id: '7' };
        render(<ReconciliationFilters />);
        expect(screen.getAllByTestId('select-Shift')[1].getAttribute('data-disabled')).toBe('true');
    });

    it('enables the shift select and loads its options once both are set', () => {
        routerMock.query = { workplace_id: '7', date: '2026-09-20' };
        filterOptionsMock.mockReturnValue({
            data: {
                data: {
                    workplaces: [{ id: 7, name: 'Factory A' }],
                    workplace_shifts: [{ id: 3, label: '09:00-18:00' }],
                },
            },
            isFetching: false,
        });

        render(<ReconciliationFilters />);

        expect(filterOptionsMock).toHaveBeenCalledWith('7', '2026-09-20');
        expect(screen.getByTestId('select-Shift').getAttribute('data-disabled')).toBe('false');
        expect(screen.getByText('09:00-18:00')).toBeInTheDocument();
    });

    it('keeps the roster status disabled without a date, since the backend answers 422', () => {
        render(<ReconciliationFilters />);
        expect(screen.getByTestId('select-Roster status').getAttribute('data-disabled')).toBe('true');
    });

    it('disables the verdict statuses until something narrows the list', () => {
        render(<ReconciliationFilters />);

        // These three enrich every candidate before they can paginate, so the backend
        // refuses them unbounded. Offering a combination that always fails is the app's bug.
        expect(optionState('Reconciliation status', 'matched')).toBe('true');
        expect(optionState('Reconciliation status', 'mismatched')).toBe('true');
        expect(optionState('Reconciliation status', 'not_comparable')).toBe('true');
        // These two resolve in SQL and need nothing to narrow them.
        expect(optionState('Reconciliation status', 'not_uploaded')).toBe('false');
        expect(optionState('Reconciliation status', 'employee_data_missing')).toBe('false');
    });

    it('enables the verdict statuses as soon as a keyword narrows the list', () => {
        routerMock.query = { keyword: 'nguyen' };

        render(<ReconciliationFilters />);

        expect(optionState('Reconciliation status', 'mismatched')).toBe('false');
    });

    it('takes the workplace options from the reconciliation endpoint, not the workplace admin list', () => {
        // That list is gated by workplace.general.list, which a reconciliation-only admin
        // does not hold: asking for it left them with an empty select and no way to a shift.
        render(<ReconciliationFilters />);

        expect(screen.getByText('Factory A')).toBeInTheDocument();
    });

    it('clears the search box along with the rest of the filters', () => {
        routerMock.query = { keyword: 'nguyen', date: '2026-09-20' };
        const { rerender } = render(<ReconciliationFilters />);
        expect((screen.getByTestId('input-Search') as HTMLInputElement).value).toBe('nguyen');

        screen.getByText('Clear filters').click();
        // Clearing rewrites the URL; the box follows it rather than keeping a word the
        // list is no longer filtered by.
        routerMock.query = { date: '2026-09-15' };
        rerender(<ReconciliationFilters />);

        expect((screen.getByTestId('input-Search') as HTMLInputElement).value).toBe('');
    });

    it('keeps what is being typed until Enter applies it', () => {
        routerMock.query = { date: '2026-09-20' };
        render(<ReconciliationFilters />);

        const input = screen.getByTestId('input-Search');
        fireEvent.change(input, { target: { value: 'tran' } });
        expect((input as HTMLInputElement).value).toBe('tran');
        expect(routerMock.push).not.toHaveBeenCalled();

        fireEvent.keyDown(input, { key: 'Enter' });
        expect(routerMock.push).toHaveBeenCalledWith({
            pathname: '/admin/user-paper-reconciliation',
            query: { date: '2026-09-20', keyword: 'tran' },
        });
    });
});
