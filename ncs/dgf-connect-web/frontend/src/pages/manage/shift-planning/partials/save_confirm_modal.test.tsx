import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import SaveConfirmModal, { DraftDiffEntry } from '@/pages/manage/shift-planning/partials/save_confirm_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    ScrollArea: { Autosize: ({ children }: any) => React.createElement('div', null, children) },
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

const buildEntry = (overrides: Partial<DraftDiffEntry> = {}): DraftDiffEntry => ({
    kind: 'add',
    liveImpact: false,
    employeeName: 'Yamada',
    dateLabel: '2026-06-26',
    chipLabel: 'Morning · Staff',
    ...overrides,
});

describe('SaveConfirmModal', () => {
    it('renders nothing when opened is false', () => {
        const { container } = render(
            <SaveConfirmModal opened={false} onClose={vi.fn()} onConfirm={vi.fn()} entries={[]} />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('shows a no-changes message and disables Save when entries is empty', () => {
        render(<SaveConfirmModal opened onClose={vi.fn()} onConfirm={vi.fn()} entries={[]} />);

        expect(screen.getByText('No changes to save.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('groups non-live-impact entries under Draft changes', () => {
        render(<SaveConfirmModal opened onClose={vi.fn()} onConfirm={vi.fn()} entries={[buildEntry()]} />);

        expect(screen.getByText('Draft changes (1)')).toBeInTheDocument();
        expect(screen.queryByText(/Live shift changes/)).not.toBeInTheDocument();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('groups live-impact entries under Live shift changes and shows the warning alert', () => {
        render(
            <SaveConfirmModal
                opened
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                entries={[buildEntry({ liveImpact: true })]}
            />,
        );

        expect(screen.getByText('Live shift changes (1)')).toBeInTheDocument();
        expect(screen.queryByText(/Draft changes/)).not.toBeInTheDocument();
        expect(screen.getByRole('alert')).toBeInTheDocument();
    });

    it('shows both sections when entries mix draft and live-impact changes', () => {
        render(
            <SaveConfirmModal
                opened
                onClose={vi.fn()}
                onConfirm={vi.fn()}
                entries={[buildEntry(), buildEntry({ liveImpact: true, employeeName: 'Tanaka' })]}
            />,
        );

        expect(screen.getByText('Draft changes (1)')).toBeInTheDocument();
        expect(screen.getByText('Live shift changes (1)')).toBeInTheDocument();
        expect(screen.getByText('Yamada', { exact: false })).toBeInTheDocument();
        expect(screen.getByText('Tanaka', { exact: false })).toBeInTheDocument();
    });

    it('enables Save changes when there are entries and calls onConfirm when clicked', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        render(<SaveConfirmModal opened onClose={vi.fn()} onConfirm={onConfirm} entries={[buildEntry()]} />);

        const saveButton = screen.getByRole('button', { name: 'Save changes' });
        expect(saveButton).not.toBeDisabled();

        await user.click(saveButton);

        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<SaveConfirmModal opened onClose={onClose} onConfirm={vi.fn()} entries={[buildEntry()]} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
