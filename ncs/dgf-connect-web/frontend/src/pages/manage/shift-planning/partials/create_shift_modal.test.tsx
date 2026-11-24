import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import CreateShiftModal, { CreateShiftValues } from '@/pages/manage/shift-planning/partials/create_shift_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    NumberInput: ({ label, value, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'text',
                value: value ?? '',
                onChange: (e: React.ChangeEvent<HTMLInputElement>) => onChange(e.target.value),
            }),
        ),
    SimpleGrid: ({ children }: any) => React.createElement('div', null, children),
    TextInput: ({ label, value }: any) =>
        React.createElement('label', null, label, React.createElement('input', { readOnly: true, value })),
}));

describe('CreateShiftModal', () => {
    const baseProps = {
        opened: true,
        onClose: vi.fn(),
        onConfirm: vi.fn(),
        date: '2026-06-26',
        shiftTypeLabel: 'Morning',
    };

    it('renders nothing when closed', () => {
        const { container } = render(<CreateShiftModal {...baseProps} opened={false} />);

        expect(container.firstChild).toBeNull();
    });

    it('shows the default title, read-only date and shift type', () => {
        render(<CreateShiftModal {...baseProps} />);

        expect(screen.getByText('Create shift')).toBeInTheDocument();
        expect(screen.getByDisplayValue('2026-06-26')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Morning')).toBeInTheDocument();
    });

    it('shows a custom title when provided', () => {
        render(<CreateShiftModal {...baseProps} title="Edit shift" />);

        expect(screen.getByText('Edit shift')).toBeInTheDocument();
    });

    it('prefills fields from initialValues when opened', () => {
        const initialValues: CreateShiftValues = {
            fixedTotal: 2,
            tempTotal: 1,
            maxRegisteredTemp: 4,
            breakMinutes: 45,
        };
        render(<CreateShiftModal {...baseProps} initialValues={initialValues} />);

        expect(screen.getByDisplayValue('2')).toBeInTheDocument();
        expect(screen.getByDisplayValue('1')).toBeInTheDocument();
        expect(screen.getByDisplayValue('4')).toBeInTheDocument();
        expect(screen.getByDisplayValue('45')).toBeInTheDocument();
    });

    it('defaults numeric fields to 0 and empty when there are no initial values', () => {
        render(<CreateShiftModal {...baseProps} />);

        expect(screen.getAllByDisplayValue('0')).toHaveLength(2);
    });

    it('calls onConfirm with numeric values, defaulting blank optional fields to null', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        render(<CreateShiftModal {...baseProps} onConfirm={onConfirm} />);

        await user.clear(screen.getByLabelText('Fixed position'));
        await user.type(screen.getByLabelText('Fixed position'), '3');
        await user.clear(screen.getByLabelText('Temporary position'));
        await user.type(screen.getByLabelText('Temporary position'), '2');

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(onConfirm).toHaveBeenCalledWith({
            fixedTotal: 3,
            tempTotal: 2,
            maxRegisteredTemp: null,
            breakMinutes: null,
        });
    });

    it('calls onConfirm with numeric maxRegisteredTemp and breakMinutes when filled', async () => {
        const user = userEvent.setup();
        const onConfirm = vi.fn();
        render(<CreateShiftModal {...baseProps} onConfirm={onConfirm} />);

        await user.type(screen.getByLabelText('Maximum registered temporary'), '5');
        await user.type(screen.getByLabelText('Break time (minute)'), '30');

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(onConfirm).toHaveBeenCalledWith(expect.objectContaining({ maxRegisteredTemp: 5, breakMinutes: 30 }));
    });

    it('disables both buttons while loading', () => {
        render(<CreateShiftModal {...baseProps} loading />);

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('calls onClose when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<CreateShiftModal {...baseProps} onClose={onClose} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
