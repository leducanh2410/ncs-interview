import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ActionBar from '@/pages/manage/shift/partials/update/action_bar';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
}));

describe('ActionBar', () => {
    const baseProps = {
        isReadOnly: false,
        hasChanges: true,
        isSaving: false,
        onCancel: vi.fn(),
        onSave: vi.fn(),
    };

    it('renders nothing when there are no changes', () => {
        const { container } = render(<ActionBar {...baseProps} hasChanges={false} />);

        expect(container.firstChild).toBeNull();
    });

    it('renders Cancel and Save buttons when there are changes', () => {
        render(<ActionBar {...baseProps} />);

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();
    });

    it('calls onCancel when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();
        render(<ActionBar {...baseProps} onCancel={onCancel} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('calls onSave when Save is clicked', async () => {
        const user = userEvent.setup();
        const onSave = vi.fn();
        render(<ActionBar {...baseProps} onSave={onSave} />);

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(onSave).toHaveBeenCalledTimes(1);
    });

    it('disables Save when isReadOnly is true', () => {
        render(<ActionBar {...baseProps} isReadOnly />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('disables Save while isSaving is true', () => {
        render(<ActionBar {...baseProps} isSaving />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });
});
