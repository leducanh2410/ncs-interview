import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PaperEmptiedStepsAlert from './paper_emptied_steps_alert';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title }: any) =>
        React.createElement('div', { 'data-testid': 'alert' }, React.createElement('h4', null, title), children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
}));

vi.mock('react-icons/lu', () => ({
    LuTriangleAlert: () => React.createElement('span', null, '!'),
}));

describe('PaperEmptiedStepsAlert', () => {
    it('renders nothing when no document is missing its images', () => {
        const { container } = render(<PaperEmptiedStepsAlert emptiedSteps={[]} canReopen onReopen={vi.fn()} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the field is absent', () => {
        const { container } = render(<PaperEmptiedStepsAlert emptiedSteps={undefined} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('lists the documents by name, including other documents', () => {
        render(<PaperEmptiedStepsAlert emptiedSteps={['work_permit', 'other']} />);

        expect(screen.getByTestId('alert')).toBeInTheDocument();
        expect(screen.getByText(/Work permit stamp, Other/)).toBeInTheDocument();
    });

    it('offers the reopen button only to an admin who can act on it', () => {
        const onReopen = vi.fn();
        const { rerender } = render(
            <PaperEmptiedStepsAlert emptiedSteps={['work_permit']} canReopen={false} onReopen={onReopen} />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(<PaperEmptiedStepsAlert emptiedSteps={['work_permit']} canReopen onReopen={onReopen} />);
        screen.getByRole('button', { name: 'Reopen these documents' }).click();

        expect(onReopen).toHaveBeenCalledOnce();
    });
});
