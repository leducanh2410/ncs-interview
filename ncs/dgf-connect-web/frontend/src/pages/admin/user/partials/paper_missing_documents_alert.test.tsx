import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PaperMissingDocumentsAlert from './paper_missing_documents_alert';

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
    LuInfo: () => React.createElement('span', null, 'i'),
}));

describe('PaperMissingDocumentsAlert', () => {
    it('renders nothing when the file is complete', () => {
        const { container } = render(<PaperMissingDocumentsAlert missingSteps={[]} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the field is absent', () => {
        const { container } = render(<PaperMissingDocumentsAlert missingSteps={undefined} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('lists the missing documents by name', () => {
        render(<PaperMissingDocumentsAlert missingSteps={['student_card', 'work_permit']} />);

        expect(screen.getByTestId('alert')).toBeInTheDocument();
        expect(screen.getByText(/Student card, Work permit stamp/)).toBeInTheDocument();
    });

    it('offers the completion request only for an approved file', () => {
        const onRequestCompletion = vi.fn();
        const { rerender } = render(
            <PaperMissingDocumentsAlert
                missingSteps={['work_permit']}
                canRequestCompletion={false}
                onRequestCompletion={onRequestCompletion}
            />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <PaperMissingDocumentsAlert
                missingSteps={['work_permit']}
                canRequestCompletion
                onRequestCompletion={onRequestCompletion}
            />,
        );

        screen.getByRole('button', { name: 'Request document completion' }).click();
        expect(onRequestCompletion).toHaveBeenCalledOnce();
    });

    it('offers the reopen only when allowed', () => {
        const onReopenSteps = vi.fn();
        const { rerender } = render(
            <PaperMissingDocumentsAlert missingSteps={['student_card']} onReopenSteps={onReopenSteps} />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <PaperMissingDocumentsAlert missingSteps={['student_card']} canReopenSteps onReopenSteps={onReopenSteps} />,
        );

        screen.getByRole('button', { name: 'Request document completion' }).click();
        expect(onReopenSteps).toHaveBeenCalledOnce();
    });

    it('shows documents already requested in the app apart from those not on file', () => {
        render(<PaperMissingDocumentsAlert missingSteps={['work_permit']} awaitingSteps={['student_card']} />);

        const alerts = screen.getAllByTestId('alert');
        expect(alerts).toHaveLength(2);
        expect(alerts[0]).toHaveTextContent('Missing documents for this residence status');
        expect(alerts[0]).toHaveTextContent('Work permit stamp');
        expect(alerts[0]).not.toHaveTextContent('Student card');
        expect(alerts[1]).toHaveTextContent('Waiting for the employee to submit documents');
        expect(alerts[1]).toHaveTextContent('Requested in the app: Student card');
    });

    it('shows only the waiting notice once every missing document is requested', () => {
        render(<PaperMissingDocumentsAlert missingSteps={[]} awaitingSteps={['student_card', 'work_permit']} />);

        expect(screen.getAllByTestId('alert')).toHaveLength(1);
        expect(screen.queryByText('Missing documents for this residence status')).not.toBeInTheDocument();
        expect(screen.getByText('Waiting for the employee to submit documents')).toBeInTheDocument();
    });

    it('falls back to the raw key for an unknown step', () => {
        render(<PaperMissingDocumentsAlert missingSteps={['future_step']} />);

        expect(screen.getByText(/future_step/)).toBeInTheDocument();
    });
});
