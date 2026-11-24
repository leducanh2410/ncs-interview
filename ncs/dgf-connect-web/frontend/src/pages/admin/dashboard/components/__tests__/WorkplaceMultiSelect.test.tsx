import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import WorkplaceMultiSelect from '@/pages/admin/dashboard/components/WorkplaceMultiSelect';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) => {
            if (!params) return key;
            return Object.entries(params).reduce(
                (acc, [name, value]) => acc.replaceAll(`{{${name}}}`, String(value)),
                key,
            );
        },
    }),
}));

const options = [
    { id: 1, name: 'Yokohama A', is_test: false },
    { id: 2, name: 'Kawasaki Pack', is_test: false },
];

const renderComponent = (onChange = vi.fn()) =>
    render(
        <MantineProvider>
            <WorkplaceMultiSelect options={options} value={[1, 2]} onChange={onChange} />
        </MantineProvider>,
    );

const getTrigger = () => {
    const trigger = screen.getAllByRole('button').find((button) => button.getAttribute('aria-haspopup') === 'dialog');
    expect(trigger).toBeInTheDocument();
    return trigger as HTMLElement;
};

describe('WorkplaceMultiSelect', () => {
    it('opens the selector dialog with keyboard activation', async () => {
        const user = userEvent.setup();
        renderComponent();

        getTrigger().focus();
        await user.keyboard('{Enter}');

        expect(getTrigger()).toHaveAttribute('aria-expanded', 'true');
    });

    it('removes a selected workplace without opening the dialog', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        renderComponent(onChange);

        await user.click(screen.getByRole('button', { name: 'Remove Yokohama A' }));

        expect(onChange).toHaveBeenCalledWith([2]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('removes a selected workplace with keyboard activation without opening the dialog', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        const { rerender } = renderComponent(onChange);

        screen.getByRole('button', { name: 'Remove Yokohama A' }).focus();
        await user.keyboard('{Enter}');

        expect(onChange).toHaveBeenLastCalledWith([2]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();

        rerender(
            <MantineProvider>
                <WorkplaceMultiSelect options={options} value={[1, 2]} onChange={onChange} />
            </MantineProvider>,
        );

        screen.getByRole('button', { name: 'Remove Yokohama A' }).focus();
        await user.keyboard('[Space]');

        expect(onChange).toHaveBeenLastCalledWith([2]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('renders the popup trigger as a non-button wrapper to avoid nested button markup', () => {
        renderComponent();

        const trigger = getTrigger();
        expect(trigger.tagName).toBe('DIV');
        expect(within(trigger).getByRole('button', { name: 'Remove Yokohama A' })).toBeInTheDocument();
    });
});
