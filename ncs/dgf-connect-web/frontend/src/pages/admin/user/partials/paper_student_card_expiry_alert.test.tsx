import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import PaperStudentCardExpiryAlert from './paper_student_card_expiry_alert';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title, color }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'alert', 'data-color': color },
            React.createElement('h4', null, title),
            children,
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
}));

vi.mock('react-icons/lu', () => ({
    LuTriangleAlert: () => React.createElement('span', null, '!'),
}));

describe('PaperStudentCardExpiryAlert', () => {
    it('renders nothing when the date is on file', () => {
        const { container } = render(<PaperStudentCardExpiryAlert missing={false} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the field is absent', () => {
        const { container } = render(<PaperStudentCardExpiryAlert missing={undefined} />);

        expect(container).toBeEmptyDOMElement();
    });

    it('states the problem and leaves the action to the button', () => {
        render(<PaperStudentCardExpiryAlert missing />);

        expect(screen.getByTestId('alert')).toHaveAttribute('data-color', 'red');
        expect(screen.getByText('Student card expiry date is missing')).toBeInTheDocument();
        expect(
            screen.getByText('The student card is on file but its expiry date has not been recorded.'),
        ).toBeInTheDocument();
    });

    /**
     * Inside the approve modal there is no button at all — a file under review cannot be
     * sent back — and Submit is disabled. The text is the only thing left to point the
     * admin at the field, so it has to name where the field is on every render.
     */
    it('names where the date is entered, with or without the button', () => {
        const guidance =
            'Enter it in Student card expiry date on the Information tab, or apply it from the OCR result, and this file can be approved.';
        const { rerender } = render(<PaperStudentCardExpiryAlert missing />);

        expect(screen.getByText(guidance)).toBeInTheDocument();

        rerender(<PaperStudentCardExpiryAlert missing canRequestCompletion onRequestCompletion={vi.fn()} />);

        expect(screen.getByText(guidance)).toBeInTheDocument();
    });

    /**
     * The second way out, for a date the admin cannot read off the images. Offered on the
     * same terms as the missing-documents alert: only an approved file can be sent back.
     */
    it('offers to ask the employee for the card again, only on an approved file', () => {
        const onRequestCompletion = vi.fn();
        const { rerender } = render(
            <PaperStudentCardExpiryAlert
                missing
                canRequestCompletion={false}
                onRequestCompletion={onRequestCompletion}
            />,
        );

        expect(screen.queryByRole('button')).not.toBeInTheDocument();

        rerender(
            <PaperStudentCardExpiryAlert missing canRequestCompletion onRequestCompletion={onRequestCompletion} />,
        );

        screen.getByRole('button', { name: 'Ask for the student card again' }).click();
        expect(onRequestCompletion).toHaveBeenCalledOnce();
    });
});
