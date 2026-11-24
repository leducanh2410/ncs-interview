import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import UserCustomConfigsCell from './user_custom_configs_cell';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, params?: Record<string, unknown>) =>
            params ? `${key}|${params.name}|${params.value}|${params.default}` : key,
    }),
}));

vi.mock('@mantine/core', () => ({
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (value: string | null) => value ?? '-' },
}));

describe('UserCustomConfigsCell', () => {
    it('renders a dash when the user has no custom configuration', () => {
        render(<UserCustomConfigsCell configs={[]} />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('renders a dash when the configs field is missing', () => {
        render(<UserCustomConfigsCell />);

        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('shows each custom value against its default with its date range', () => {
        render(
            <UserCustomConfigsCell
                configs={[
                    {
                        key: 'max_hour_per_week',
                        value: 30,
                        default_value: 28,
                        start_date: '2026-10-01',
                        expiry_date: '2026-12-31',
                    },
                    {
                        key: 'max_shift_per_day',
                        value: 3,
                        default_value: 2,
                        start_date: null,
                        expiry_date: null,
                    },
                ]}
            />,
        );

        expect(
            screen.getByText('{{name}}: {{value}} (default: {{default}})|Max hours per week|30|28'),
        ).toBeInTheDocument();
        expect(screen.getByText('2026-10-01 ~ 2026-12-31')).toBeInTheDocument();
        expect(
            screen.getByText('{{name}}: {{value}} (default: {{default}})|Max shifts per day|3|2'),
        ).toBeInTheDocument();
        expect(screen.getByText('- ~ -')).toBeInTheDocument();
    });
});
