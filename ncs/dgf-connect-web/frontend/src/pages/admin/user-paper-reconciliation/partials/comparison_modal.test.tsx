import React from 'react';
import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ComparisonModal from '@/pages/admin/user-paper-reconciliation/partials/comparison_modal';

const { detailMock } = vi.hoisted(() => ({ detailMock: vi.fn() }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
    // The shared OCR warning wording comes off the real i18n instance, which initialises
    // itself against this module the moment it is imported.
    initReactI18next: { type: '3rdParty', init: () => undefined },
}));

vi.mock('@/pages/admin/user-paper-reconciliation/api', () => ({
    useGetPaperReconciliationDetailQuery: () => detailMock(),
}));

vi.mock('@/pages/admin/user-paper-reconciliation/partials/field_comparison_table', () => ({
    default: () => React.createElement('div', { 'data-testid': 'field-table' }),
}));

vi.mock('@/pages/admin/user-paper-reconciliation/partials/verdict_badge', () => ({
    default: ({ verdict }: any) => React.createElement('span', null, verdict),
}));

vi.mock('@/helpers/date.helper', () => ({ default: { parseAndFormat: (value: string) => value } }));

vi.mock('@/helpers/url.helper', () => ({ default: { getStaticUrl: (path: string) => `/static/${path}` } }));

vi.mock('@mantine/core', () => {
    const passthrough = (tag: string) => (props: any) => React.createElement(tag, null, props.children);

    return {
        Alert: ({ title, children }: any) => React.createElement('div', null, title, children),
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: passthrough('span'),
        Divider: ({ label }: any) => React.createElement('div', null, label),
        Group: passthrough('div'),
        Image: ({ alt }: any) => React.createElement('img', { alt }),
        Loader: () => React.createElement('div', null, 'loading'),
        Modal: passthrough('div'),
        Stack: passthrough('div'),
        Text: passthrough('span'),
        Title: passthrough('h2'),
    };
});

const adminScan = (id: number) => ({
    id,
    file_path: `scan-${id}.jpg`,
    submitted_at: '2026-09-10 10:00',
    submitted_by: { id: 1, name: 'Admin One' },
    confirmed_without_ocr: false,
});

const detail = (overrides: Record<string, unknown> = {}) => ({
    data: {
        data: {
            user: { id: 5, staff_no: 'EMP-5', last_name: 'NGUYEN VAN', first_name: 'A' },
            employee_scan: null,
            admin_scan: adminScan(2),
            verdict: 'matched',
            mismatch_count: 0,
            employee_residence_missing: false,
            current_slot: 1,
            fields: [],
            employee_warnings: [],
            admin_warnings: [],
            history: [{ ...adminScan(2), verdict: 'matched', mismatch_count: 0 }],
            ...overrides,
        },
    },
    isPending: false,
});

describe('ComparisonModal', () => {
    beforeEach(() => detailMock.mockReturnValue(detail()));

    it('does not list the scan shown above as a previous one', () => {
        // On a first submission `history` holds exactly the scan in the pane, and calling
        // that a previous scan tells the reviewer a past exists that does not.
        render(<ComparisonModal userId={5} onClose={() => {}} />);

        expect(screen.queryByText('Previous admin scans')).not.toBeInTheDocument();
    });

    it('lists the scans the current one replaced', () => {
        detailMock.mockReturnValue(
            detail({
                history: [
                    { ...adminScan(2), verdict: 'matched', mismatch_count: 0 },
                    { ...adminScan(1), verdict: 'mismatched', mismatch_count: 2 },
                ],
            }),
        );

        render(<ComparisonModal userId={5} onClose={() => {}} />);

        expect(screen.getByText('Previous admin scans')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View image' })).toHaveAttribute('href', '/static/scan-1.jpg');
    });

    it('says what an OCR warning means instead of printing its code', () => {
        detailMock.mockReturnValue(detail({ admin_warnings: ['residence_number_format', 'unknown_future_code'] }));

        render(<ComparisonModal userId={5} onClose={() => {}} />);

        expect(screen.getByText('Residence card number format looks invalid')).toBeInTheDocument();
        // An unmapped code still names the field to look at, which beats saying nothing.
        expect(screen.getByText('unknown_future_code')).toBeInTheDocument();
    });
});
