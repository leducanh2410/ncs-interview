import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import FinalizeResultModal, { FinalizeDateResult } from '@/pages/manage/shift-planning/partials/finalize_result_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    ThemeIcon: ({ children }: any) => React.createElement('span', null, children),
}));

const buildResult = (overrides: Partial<FinalizeDateResult> = {}): FinalizeDateResult => ({
    dateLabel: '2026-06-26',
    committed: true,
    notifiedCount: 2,
    errors: [],
    ...overrides,
});

describe('FinalizeResultModal', () => {
    it('renders nothing when opened is false', () => {
        const { container } = render(<FinalizeResultModal opened={false} onClose={vi.fn()} results={[]} />);

        expect(container.firstChild).toBeNull();
    });

    it('shows committed/failed/notified summary counts', () => {
        render(
            <FinalizeResultModal
                opened
                onClose={vi.fn()}
                results={[
                    buildResult({ notifiedCount: 3 }),
                    buildResult({ dateLabel: '2026-06-27', committed: false, notifiedCount: 0, errors: ['bad'] }),
                ]}
            />,
        );

        expect(screen.getByText('1 committed')).toBeInTheDocument();
        expect(screen.getByText('1 failed')).toBeInTheDocument();
        expect(screen.getByText('3 notified')).toBeInTheDocument();
    });

    it('shows the committed message for committed dates', () => {
        render(<FinalizeResultModal opened onClose={vi.fn()} results={[buildResult({ notifiedCount: 5 })]} />);

        expect(screen.getByText('Committed · 5 employee(s) notified')).toBeInTheDocument();
    });

    it('shows the failure message and per-cell errors for a failed date', () => {
        render(
            <FinalizeResultModal
                opened
                onClose={vi.fn()}
                results={[buildResult({ committed: false, notifiedCount: 0, errors: ['Missing position'] })]}
            />,
        );

        expect(screen.getByText('Not committed — fix the errors below and resubmit')).toBeInTheDocument();
        expect(screen.getByText('Missing position')).toBeInTheDocument();
    });

    it('calls onClose when Close is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<FinalizeResultModal opened onClose={onClose} results={[buildResult()]} />);

        await user.click(screen.getByRole('button', { name: 'Close' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
