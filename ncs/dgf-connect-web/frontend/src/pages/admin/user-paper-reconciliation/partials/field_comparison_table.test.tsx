import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PaperFieldComparison } from '@/types/models/paper_reconciliation';
import FieldComparisonTable from '@/pages/admin/user-paper-reconciliation/partials/field_comparison_table';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key),
    }),
}));

// EnumHelper reaches i18n at import time, which this file's react-i18next mock does not
// satisfy; the established pattern is to stub the helper itself.
vi.mock('@/helpers/enum.helper', () => ({
    default: { getText: (_enum: unknown, value: string) => `label:${value}` },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string) => `date:${value}` },
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Table: Object.assign(({ children }: any) => React.createElement('table', null, children), {
        Thead: ({ children }: any) => React.createElement('thead', null, children),
        Tbody: ({ children }: any) => React.createElement('tbody', null, children),
        Tr: ({ children, bg }: any) => React.createElement('tr', { 'data-bg': bg ?? '' }, children),
        Th: ({ children }: any) => React.createElement('th', null, children),
        Td: ({ children }: any) => React.createElement('td', null, children),
    }),
}));

const field = (overrides: Partial<PaperFieldComparison>): PaperFieldComparison => ({
    field: 'name',
    employee: 'NGUYEN VAN A',
    admin: 'NGUYEN VAN A',
    compared: true,
    verdict: 'match',
    ...overrides,
});

describe('FieldComparisonTable', () => {
    it('renders an uncomparable field as "cannot compare", never in the match style', () => {
        render(<FieldComparisonTable fields={[field({ verdict: 'unknown', employee: null })]} />);

        const badge = screen.getByText('Cannot compare');
        expect(badge).toBeInTheDocument();
        expect(badge.getAttribute('data-color')).toBe('gray');
        expect(screen.queryByText('Matches')).not.toBeInTheDocument();
    });

    it('renders display-only fields without a verdict and says they are not compared', () => {
        render(<FieldComparisonTable fields={[field({ field: 'address', compared: false, verdict: null })]} />);

        expect(screen.getByText('Shown for reference, never compared')).toBeInTheDocument();
        expect(screen.queryByText('Matches')).not.toBeInTheDocument();
        expect(screen.queryByText('Differs')).not.toBeInTheDocument();
    });

    it('says in words that a mismatching card is one the record has already replaced', () => {
        render(
            <FieldComparisonTable
                fields={[
                    field({
                        field: 'residence_number',
                        verdict: 'mismatch',
                        employee: 'EF87654321GH',
                        admin: 'AB12345678CD',
                        matches_previous_slot: 1,
                    }),
                ]}
            />,
        );

        // The two situations lead a reviewer to opposite conclusions, so the superseded
        // card must never be left looking like a card the record has never seen.
        expect(screen.getByText(/This card is one the record has already replaced/)).toBeInTheDocument();
    });

    it('renders stored codes through their enum label, not the raw value', () => {
        render(
            <FieldComparisonTable
                fields={[
                    field({
                        field: 'residence_status',
                        employee: 'permanent_resident',
                        admin: 'study_abroad',
                        verdict: 'mismatch',
                    }),
                ]}
            />,
        );

        // Printing the stored key at the reviewer is what this guards against: both sides
        // arrive as enum values, and "permanent_resident" is not a reading of a card.
        expect(screen.getByText('label:permanent_resident')).toBeInTheDocument();
        expect(screen.getByText('label:study_abroad')).toBeInTheDocument();
        expect(screen.queryByText('permanent_resident')).not.toBeInTheDocument();
    });

    it('formats date fields instead of showing the stored ISO value', () => {
        render(
            <FieldComparisonTable
                fields={[
                    field({
                        field: 'residence_expiry_date',
                        employee: '2030-01-31',
                        admin: '2030-01-31',
                    }),
                ]}
            />,
        );

        expect(screen.getAllByText('date:2030-01-31')).toHaveLength(2);
    });

    it('shades reference rows apart from the rows that can actually disagree', () => {
        const { container } = render(
            <FieldComparisonTable
                fields={[
                    field({ field: 'name', verdict: 'match' }),
                    field({ field: 'address', compared: false, verdict: null }),
                    field({ field: 'residence_number', verdict: 'mismatch' }),
                ]}
            />,
        );

        const shades = Array.from(container.querySelectorAll('tbody tr')).map((row) => row.getAttribute('data-bg'));

        // A reviewer reads the judged rows; the reference rows must not compete with them,
        // and a mismatch must keep a shade of its own.
        expect(shades[0]).toBe('');
        expect(shades[1]).toBe('var(--mantine-color-gray-1)');
        expect(shades[2]).toBe('var(--mantine-color-red-0)');
    });

    it('does not claim a superseded card when the number matches no slot', () => {
        render(
            <FieldComparisonTable
                fields={[field({ field: 'residence_number', verdict: 'mismatch', matches_previous_slot: null })]}
            />,
        );

        expect(screen.queryByText(/already replaced/)).not.toBeInTheDocument();
        expect(screen.getByText('Differs')).toBeInTheDocument();
    });
});
