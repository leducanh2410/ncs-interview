import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ValidationErrorsModal, {
    ValidationErrorEntry,
} from '@/pages/manage/shift-planning/partials/validation_errors_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { type: 'button', onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

const entries: ValidationErrorEntry[] = [
    { employeeName: 'Yamada', dateLabel: '2026-06-26', reason: 'Missing position' },
    { employeeName: 'Tanaka', dateLabel: '2026-06-26', reason: 'Duplicate shift' },
    { employeeName: 'Sato', dateLabel: '2026-06-27', reason: 'Overtime limit' },
];

describe('ValidationErrorsModal', () => {
    it('renders nothing when opened is false', () => {
        const { container } = render(
            <ValidationErrorsModal opened={false} onClose={vi.fn()} entries={[]} trigger="save" />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('shows the save title and copy for the save trigger', () => {
        render(<ValidationErrorsModal opened onClose={vi.fn()} entries={[]} trigger="save" />);

        expect(screen.getByText('Cannot save draft')).toBeInTheDocument();
        expect(
            screen.getByText('Fix the following cells before saving the draft. They stay marked until resolved.'),
        ).toBeInTheDocument();
    });

    it('shows the finalize title and copy for the finalize trigger', () => {
        render(<ValidationErrorsModal opened onClose={vi.fn()} entries={[]} trigger="finalize" />);

        expect(screen.getByText('Cannot finalize')).toBeInTheDocument();
        expect(
            screen.getByText('Some selected dates have blocking errors. Fix the cells below and resubmit.'),
        ).toBeInTheDocument();
    });

    it('shows a no-errors message when entries is empty', () => {
        render(<ValidationErrorsModal opened onClose={vi.fn()} entries={[]} trigger="save" />);

        expect(screen.getByText('No validation errors.')).toBeInTheDocument();
    });

    it('groups entries by date, preserving first-appearance order', () => {
        render(<ValidationErrorsModal opened onClose={vi.fn()} entries={entries} trigger="save" />);

        const dateHeadings = screen.getAllByText(/2026-06-2[67]/);
        expect(dateHeadings.map((el) => el.textContent)).toEqual(['2026-06-26', '2026-06-27']);
        expect(screen.getByText(/Yamada/)).toBeInTheDocument();
        expect(screen.getByText(/Missing position/)).toBeInTheDocument();
        expect(screen.getByText(/Overtime limit/)).toBeInTheDocument();
    });

    it('calls onClose when Close is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<ValidationErrorsModal opened onClose={onClose} entries={[]} trigger="save" />);

        await user.click(screen.getByRole('button', { name: 'Close' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
