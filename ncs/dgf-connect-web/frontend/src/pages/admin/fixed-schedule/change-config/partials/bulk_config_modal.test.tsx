import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import BulkConfigModal, {
    BulkConfigRequest,
} from '@/pages/admin/fixed-schedule/change-config/partials/bulk_config_modal';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: any) => (opts?.count !== undefined ? `${key}:${opts.count}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled }: any) => React.createElement('button', { onClick, disabled }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, title }: any) => React.createElement('div', { 'data-testid': 'modal' }, [title, children]),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ value, onChange, minDate }: any) =>
        React.createElement('input', {
            'data-testid': 'date-input',
            'value': value ?? '',
            'data-min-date': minDate,
            'onChange': (e: React.ChangeEvent<HTMLInputElement>) => onChange?.(e.target.value),
        }),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({
            format: () => '2026-07-16',
            add: () => ({ format: () => '2026-07-17' }),
        }),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

const makeRequest = (overrides: Partial<BulkConfigRequest> = {}): BulkConfigRequest => ({
    workplaceIds: [10, 11],
    workplaceNames: ['Tokyo HQ', 'Osaka'],
    isEnabled: true,
    ...overrides,
});

describe('BulkConfigModal', () => {
    it('renders nothing when there is no request', () => {
        const { container } = render(
            <BulkConfigModal request={null} onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('disables Confirm until a deadline is chosen when enabling', () => {
        render(<BulkConfigModal request={makeRequest()} onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />);

        expect(screen.getByText('Confirm')).toBeDisabled();
    });

    it('enables Confirm once a deadline is picked and passes it through on click', () => {
        const onConfirm = vi.fn();
        render(
            <BulkConfigModal request={makeRequest()} onConfirm={onConfirm} onCancel={vi.fn()} isProcessing={false} />,
        );

        fireEvent.change(screen.getByTestId('date-input'), { target: { value: '2026-08-15' } });

        expect(screen.getByText('Confirm')).not.toBeDisabled();
        fireEvent.click(screen.getByText('Confirm'));
        expect(onConfirm).toHaveBeenCalledWith('2026-08-15');
    });

    it('does not require a deadline when disabling', () => {
        render(
            <BulkConfigModal
                request={makeRequest({ isEnabled: false })}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
                isProcessing={false}
            />,
        );

        expect(screen.queryByTestId('date-input')).not.toBeInTheDocument();
        expect(screen.getByText('Confirm')).not.toBeDisabled();
    });

    it('restricts the date picker to dates on or after today', () => {
        render(<BulkConfigModal request={makeRequest()} onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />);

        expect(screen.getByTestId('date-input')).toHaveAttribute('data-min-date', '2026-07-16');
    });

    it('enables Confirm if the picked deadline is today', () => {
        render(<BulkConfigModal request={makeRequest()} onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />);

        fireEvent.change(screen.getByTestId('date-input'), { target: { value: '2026-07-16' } });

        expect(screen.getByText('Confirm')).not.toBeDisabled();
    });

    it('resets the chosen deadline when a new request comes in', () => {
        const { rerender } = render(
            <BulkConfigModal request={makeRequest()} onConfirm={vi.fn()} onCancel={vi.fn()} isProcessing={false} />,
        );

        fireEvent.change(screen.getByTestId('date-input'), { target: { value: '2026-08-15' } });
        expect(screen.getByText('Confirm')).not.toBeDisabled();

        rerender(
            <BulkConfigModal
                request={makeRequest({ workplaceIds: [12] })}
                onConfirm={vi.fn()}
                onCancel={vi.fn()}
                isProcessing={false}
            />,
        );

        expect(screen.getByText('Confirm')).toBeDisabled();
    });
});
