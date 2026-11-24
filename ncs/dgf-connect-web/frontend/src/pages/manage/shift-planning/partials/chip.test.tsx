import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Chip from '@/pages/manage/shift-planning/partials/chip';
import { PlanChip, setRegistries } from '@/pages/manage/shift-planning/partials/data';

vi.mock('@mantine/core', () => ({
    CloseButton: ({ onClick, c }: any) =>
        React.createElement('button', { 'type': 'button', 'aria-label': 'remove', 'data-color': c, onClick }),
}));

const buildChip = (overrides: Partial<PlanChip> = {}): PlanChip => ({
    id: 'chip-1',
    shiftTypeId: 1,
    positionId: 1,
    isFixed: true,
    source: 'live',
    ...overrides,
});

describe('Chip', () => {
    beforeEach(() => {
        setRegistries(
            [{ id: 1, label: 'Morning', startTime: '09:00', endTime: '18:00', isActive: true }],
            [{ id: 1, name: 'Staff', isActive: true }],
        );
    });

    it('renders the shift time and position name', () => {
        render(<Chip chip={buildChip()} />);

        expect(screen.getByText('09:00~18:00')).toBeInTheDocument();
        expect(screen.getByText('Staff')).toBeInTheDocument();
    });

    it('renders "?" for a shift type or position not found in the registry', () => {
        render(<Chip chip={buildChip({ shiftTypeId: 999, positionId: null })} />);

        expect(screen.getByText('?')).toBeInTheDocument();
    });

    it('calls onSelect when clicked', async () => {
        const user = userEvent.setup();
        const onSelect = vi.fn();
        render(<Chip chip={buildChip()} onSelect={onSelect} />);

        await user.click(screen.getByText('Staff'));

        expect(onSelect).toHaveBeenCalledTimes(1);
    });

    it('does not render a remove button when onRemove is not provided', () => {
        render(<Chip chip={buildChip()} />);

        expect(screen.queryByRole('button', { name: 'remove' })).not.toBeInTheDocument();
    });

    it('calls onRemove without triggering onSelect when the close button is clicked', async () => {
        const user = userEvent.setup();
        const onSelect = vi.fn();
        const onRemove = vi.fn();
        render(<Chip chip={buildChip()} onSelect={onSelect} onRemove={onRemove} />);

        await user.click(screen.getByRole('button', { name: 'remove' }));

        expect(onRemove).toHaveBeenCalledTimes(1);
        expect(onSelect).not.toHaveBeenCalled();
    });

    it('uses red close color for a chip staged for removal', () => {
        render(<Chip chip={buildChip({ action: 'remove' })} onRemove={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'remove' })).toHaveAttribute('data-color', 'red');
    });

    it('uses white close color for a finalized live chip', () => {
        render(<Chip chip={buildChip()} onRemove={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'remove' })).toHaveAttribute('data-color', 'white');
    });

    it('uses teal close color for a fixed draft chip', () => {
        render(<Chip chip={buildChip({ source: 'draft', action: 'add', isFixed: true })} onRemove={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'remove' })).toHaveAttribute('data-color', 'teal');
    });

    it('uses blue close color for a temp draft chip', () => {
        render(<Chip chip={buildChip({ source: 'draft', action: 'add', isFixed: false })} onRemove={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'remove' })).toHaveAttribute('data-color', 'blue');
    });
});
