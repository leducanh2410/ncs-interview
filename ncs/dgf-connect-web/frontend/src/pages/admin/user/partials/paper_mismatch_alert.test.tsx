import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PaperMismatchAlert from './paper_mismatch_alert';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title }: any) =>
        React.createElement('div', { 'data-testid': 'alert' }, React.createElement('h4', null, title), children),
    List: Object.assign(({ children }: any) => React.createElement('ul', null, children), {
        Item: ({ children }: any) => React.createElement('li', null, children),
    }),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('react-icons/lu', () => ({
    LuTriangleAlert: () => React.createElement('span', null, '!'),
}));

const nameMismatch = {
    field: 'name',
    values: [
        { group: 'residence_card', value: 'NGUYEN VAN A' },
        { group: 'residence_card_scan', value: 'TRAN THI B' },
    ],
};

describe('PaperMismatchAlert', () => {
    it('renders nothing when the documents agree', () => {
        const { container } = render(<PaperMismatchAlert mismatches={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the paper has no verdict yet', () => {
        const { container } = render(<PaperMismatchAlert mismatches={null} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('names the field and every document that disagrees', () => {
        render(<PaperMismatchAlert mismatches={[nameMismatch]} />);

        expect(screen.getByTestId('alert')).toBeInTheDocument();
        expect(screen.getByText('Full name:')).toBeInTheDocument();
        expect(screen.getByText(/Residence card \(front\/back\) — NGUYEN VAN A/)).toBeInTheDocument();
        expect(screen.getByText(/Residence card \(scan\) — TRAN THI B/)).toBeInTheDocument();
    });

    it('names the residence card number, the field the two card readings exist to compare', () => {
        render(
            <PaperMismatchAlert
                mismatches={[
                    {
                        field: 'residence_number',
                        values: [
                            { group: 'residence_card', value: 'AB12345678CD' },
                            { group: 'residence_card_scan', value: 'ZZ99999999ZZ' },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getByText('Residence number:')).toBeInTheDocument();
        expect(screen.queryByText('residence_number:')).not.toBeInTheDocument();
        expect(screen.getByText(/Residence card \(scan\) — ZZ99999999ZZ/)).toBeInTheDocument();
    });

    it('falls back to the raw key for a field or group it has no label for', () => {
        render(
            <PaperMismatchAlert
                mismatches={[
                    {
                        field: 'unknown_field',
                        values: [
                            { group: 'passport', value: 'A' },
                            { group: 'future_group', value: 'B' },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getByText('unknown_field:')).toBeInTheDocument();
        expect(screen.getByText(/future_group — B/)).toBeInTheDocument();
    });

    it('lists one entry per disagreeing field', () => {
        render(
            <PaperMismatchAlert
                mismatches={[
                    nameMismatch,
                    {
                        field: 'birthday',
                        values: [
                            { group: 'residence_card', value: '2000-01-01' },
                            { group: 'passport', value: '1999-12-31' },
                        ],
                    },
                ]}
            />,
        );

        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });
});
