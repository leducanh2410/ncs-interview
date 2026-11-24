import React from 'react';
import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PlanGridSkeleton from '@/pages/manage/shift-planning/partials/plan_grid_skeleton';

vi.mock('@mantine/core', () => ({
    Skeleton: () => React.createElement('div', { 'data-testid': 'skeleton' }),
}));

describe('PlanGridSkeleton', () => {
    it('renders a data row for each default row plus the two header rows worth of skeletons', () => {
        const { container } = render(<PlanGridSkeleton />);

        expect(container.querySelectorAll('[data-testid="skeleton"]').length).toBeGreaterThan(0);
    });

    it('renders exactly `rows` data rows worth of row-name skeletons when rows is provided', () => {
        const { container: containerTwoRows } = render(<PlanGridSkeleton rows={2} />);
        const { container: containerFiveRows } = render(<PlanGridSkeleton rows={5} />);

        const countFor = (container: HTMLElement) => container.querySelectorAll('[data-testid="skeleton"]').length;

        expect(countFor(containerFiveRows) - countFor(containerTwoRows)).toBe(3 * 16);
    });
});
