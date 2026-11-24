import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import PostalCodeFromAddressButton from './postal_code_from_address_button';

const { resolveMock, showInfoMock, showErrorMock, isPendingRef } = vi.hoisted(() => ({
    resolveMock: vi.fn(),
    showInfoMock: vi.fn(),
    showErrorMock: vi.fn(),
    isPendingRef: { current: false },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, loading, 'aria-label': ariaLabel }: any) =>
        React.createElement(
            'button',
            { 'type': 'button', onClick, 'disabled': disabled || loading, 'aria-label': ariaLabel },
            children,
        ),
    Tooltip: ({ children }: any) => React.createElement(React.Fragment, null, children),
}));

vi.mock('react-icons/lu', () => ({
    LuSearch: () => React.createElement('span', null, 'search'),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showInfo: (...args: unknown[]) => showInfoMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/admin/user/api', () => ({
    useResolvePostalCodeMutation: () => ({ mutateAsync: resolveMock, isPending: isPendingRef.current }),
}));

const resolution = (overrides: Record<string, unknown> = {}) => ({
    data: {
        postal_code: null,
        confidence: 'none',
        matched: { prefecture: null, city: null, town: null, rest: '' },
        candidates: [],
        ...overrides,
    },
});

describe('PostalCodeFromAddressButton', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        isPendingRef.current = false;
    });

    it('is disabled until an address is entered', () => {
        render(<PostalCodeFromAddressButton address="   " onResolved={vi.fn()} />);

        expect(screen.getByLabelText('Find postal code from address')).toBeDisabled();
    });

    it('resolves the address with the prefecture hint and reports an exact code', async () => {
        const user = userEvent.setup();
        const onResolved = vi.fn();
        resolveMock.mockResolvedValue(resolution({ postal_code: '3500235', confidence: 'exact' }));
        render(<PostalCodeFromAddressButton address="坂戸市三光町34-6" prefecture="埼玉県" onResolved={onResolved} />);

        await user.click(screen.getByLabelText('Find postal code from address'));

        await waitFor(() => expect(onResolved).toHaveBeenCalledWith('3500235'));
        expect(resolveMock).toHaveBeenCalledWith({ address: '坂戸市三光町34-6', prefecture: '埼玉県' });
        expect(showInfoMock).not.toHaveBeenCalled();
    });

    it('tells the admin when the address does not settle on one code', async () => {
        const user = userEvent.setup();
        const onResolved = vi.fn();
        resolveMock.mockResolvedValue(
            resolution({
                confidence: 'ambiguous',
                candidates: [{ postal_code: '5300001' }, { postal_code: '5300002' }],
            }),
        );
        render(<PostalCodeFromAddressButton address="大阪府大阪市北区梅田" onResolved={onResolved} />);

        await user.click(screen.getByLabelText('Find postal code from address'));

        await waitFor(() =>
            expect(showInfoMock).toHaveBeenCalledWith('The postal code could not be determined from the address'),
        );
        expect(onResolved).not.toHaveBeenCalled();
    });

    it('shows an error when the lookup request fails', async () => {
        const user = userEvent.setup();
        resolveMock.mockRejectedValue(new Error('network'));
        render(<PostalCodeFromAddressButton address="埼玉県坂戸市三光町34-6" onResolved={vi.fn()} />);

        await user.click(screen.getByLabelText('Find postal code from address'));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not look up the postal code'));
    });

    it('is disabled while a lookup is running', () => {
        isPendingRef.current = true;
        render(<PostalCodeFromAddressButton address="埼玉県坂戸市三光町34-6" onResolved={vi.fn()} />);

        expect(screen.getByLabelText('Find postal code from address')).toBeDisabled();
    });
});
