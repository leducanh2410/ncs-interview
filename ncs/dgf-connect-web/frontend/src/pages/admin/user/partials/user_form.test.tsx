import React, { createRef } from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserForm, { UserFormHandle } from './user_form';

const {
    updateUserMock,
    usePermissionsMock,
    showErrorMock,
    setFormErrorMock,
    toParamsMock,
    postalCodeGetMock,
    isPendingRef,
    FakeApiError,
} = vi.hoisted(() => ({
    updateUserMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showErrorMock: vi.fn(),
    setFormErrorMock: vi.fn(),
    toParamsMock: vi.fn(),
    postalCodeGetMock: vi.fn(),
    isPendingRef: { current: false },
    FakeApiError: class FakeApiError extends Error {
        status = 422;
        data: Record<string, unknown> = {};

        constructor({ message }: { message: string }) {
            super(message);
            this.message = message;
        }
    },
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
    // The validator module pulls in the shared i18n instance, which registers this plugin.
    initReactI18next: { type: '3rdParty', init: () => {} },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, href }: any) => React.createElement('a', { href }, children),
    Checkbox: ({ label, checked, onChange, disabled }: any) =>
        React.createElement('input', {
            'type': 'checkbox',
            'aria-label': label,
            'checked': !!checked,
            disabled,
            onChange,
        }),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Grid: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Col: ({ children }: any) => React.createElement('div', null, children),
    }),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Paper: ({ children }: any) => React.createElement('section', null, children),
    Select: ({ label, value, onChange, data }: any) =>
        React.createElement(
            'select',
            { 'aria-label': label, 'value': value ?? '', 'onChange': (e: any) => onChange(e.target.value) },
            [
                React.createElement('option', { key: '__empty', value: '' }, ''),
                ...(data ?? []).map((opt: any) =>
                    React.createElement('option', { key: opt.value, value: opt.value }, opt.label),
                ),
            ],
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({
        label,
        value,
        onChange,
        onBlur,
        error,
        disabled,
        rightSection,
        rightSectionPointerEvents,
        withAsterisk,
    }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('input', {
                'aria-label': label,
                'value': value ?? '',
                onChange,
                onBlur,
                disabled,
                'data-error': error ?? '',
                'data-asterisk': withAsterisk ? 'yes' : 'no',
                'data-right-section-pointer-events': rightSectionPointerEvents ?? '',
            }),
            rightSection ?? null,
        ),
}));

vi.mock('@mantine/dates', () => ({
    DatePickerInput: ({ label, value, onChange, error, withAsterisk, clearable }: any) =>
        React.createElement('input', {
            'aria-label': label,
            'value': value ?? '',
            'onChange': (e: any) => onChange(e.target.value),
            'data-error': error ?? '',
            'data-asterisk': withAsterisk ? 'yes' : 'no',
            'data-clearable': clearable ? 'yes' : 'no',
        }),
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => React.createElement('span', null, '📅'),
    LuExternalLink: () => React.createElement('span', null, '↗'),
}));

vi.mock('japan-postal-code', () => ({
    default: { get: (...args: unknown[]) => postalCodeGetMock(...args) },
}));

// Covered by its own test; here only the wiring to the form matters
vi.mock('@/pages/admin/user/partials/postal_code_from_address_button', () => ({
    default: ({ address, prefecture, onResolved }: any) =>
        React.createElement(
            'button',
            {
                'type': 'button',
                'aria-label': 'Find postal code from address',
                'data-address': address,
                'data-prefecture': prefecture ?? '',
                'onClick': () => onResolved('3500235'),
            },
            'find',
        ),
}));

vi.mock('@/helpers/api.helper', () => ({ ApiError: FakeApiError }));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: (...args: unknown[]) => showErrorMock(...args) },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: {
        toParams: (...args: unknown[]) => toParamsMock(...args),
        setFormError: (...args: unknown[]) => setFormErrorMock(...args),
    },
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children, title }: any) => React.createElement('div', { 'data-testid': 'alert' }, title, children),
}));

vi.mock('./user_form.module.css', () => ({ default: {} }));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useUpdateUserMutation: () => ({ mutateAsync: updateUserMock, isPending: isPendingRef.current }),
}));

const buildUser = (overrides: Record<string, unknown> = {}) =>
    ({
        id: 42,
        first_name: 'JOHN',
        last_name: 'DOE',
        first_name_kana: 'ジョン',
        last_name_kana: 'ドウ',
        email: 'john@example.com',
        staff_no: 'S100',
        is_test: false,
        paper_status: 'approved',
        passport_expiry_alert: false,
        student_card_expiry_alert: false,
        residence_card_expiry_alert: false,
        info: {
            birthday: '1995-05-15',
            gender: 'male',
            nationality: 'vietnam',
            phone: '09011112222',
            emergency_contact_phone: '09033334444',
            postal_code: '1000001',
            prefecture: 'Tokyo',
            address: 'Chiyoda',
            registered_location: 'HQ',
            japanese_level: 'n2',
            residence_number_1: 'AB1234567890',
            residence_expiry_date_1: '2030-01-01',
            residence_status_1: 'engineer',
            residence_number_2: null,
            residence_expiry_date_2: null,
            residence_status_2: null,
            residence_number_3: null,
            residence_expiry_date_3: null,
            residence_status_3: null,
            passport_number: 'P123',
            passport_expiry_date: '2031-02-03',
            student_card_expiry_date: null,
            is_household_head: true,
            facebook_link: 'john.doe',
            line_link: 'johnline',
        },
        ...overrides,
    }) as any;

const onSaved = vi.fn();
const onSavingChange = vi.fn();

const renderForm = (props: Record<string, unknown> = {}) =>
    render(
        <UserForm
            id="42"
            user={buildUser()}
            isLoading={false}
            isReadOnly={false}
            formId="user-form"
            onSaved={onSaved}
            onSavingChange={onSavingChange}
            {...props}
        />,
    );

const submitForm = async (user: ReturnType<typeof userEvent.setup>) => {
    const form = document.getElementById('user-form') as HTMLFormElement;
    await user.click(document.body); // flush any pending blur handlers
    form.requestSubmit();
};

describe('UserForm', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        isPendingRef.current = false;
        usePermissionsMock.mockReturnValue({ USER_EDIT: true, USER_PAPER_APPROVE: true });
        toParamsMock.mockImplementation((values: unknown) => values);
        updateUserMock.mockResolvedValue({});
    });

    describe('loading', () => {
        it('renders a loader while loading', () => {
            renderForm({ isLoading: true });

            expect(screen.getByTestId('loader')).toBeInTheDocument();
        });

        it('renders a loader when there is no user yet', () => {
            renderForm({ user: null, isLoading: false });

            expect(screen.getByTestId('loader')).toBeInTheDocument();
        });
    });

    describe('edit mode', () => {
        it('populates the inputs from the user and its info', () => {
            renderForm();

            expect(screen.getByLabelText('Last name')).toHaveValue('DOE');
            expect(screen.getByLabelText('First name')).toHaveValue('JOHN');
            expect(screen.getByLabelText('Email')).toHaveValue('john@example.com');
            expect(screen.getByLabelText('Phone')).toHaveValue('09011112222');
            expect(screen.getByLabelText('Prefecture')).toHaveValue('Tokyo');
            expect(screen.getByLabelText('Staff number')).toHaveValue('S100');
            expect(screen.getByLabelText('Passport number')).toHaveValue('P123');
            expect(screen.getAllByLabelText('Residence number')[0]).toHaveValue('AB1234567890');
        });

        it('checks the household head box from the user info', () => {
            renderForm();

            expect(screen.getByLabelText('Household Head')).toBeChecked();
        });

        it('disables the staff number unless the paper status is approved', () => {
            renderForm({ user: buildUser({ paper_status: 'registered' }) });

            expect(screen.getByLabelText('Staff number')).toBeDisabled();
        });

        it('disables the staff number without USER_EDIT permission', () => {
            usePermissionsMock.mockReturnValue({ USER_EDIT: false, USER_PAPER_APPROVE: true });

            renderForm();

            expect(screen.getByLabelText('Staff number')).toBeDisabled();
        });

        it('enables the staff number for an approved user with USER_EDIT', () => {
            renderForm();

            expect(screen.getByLabelText('Staff number')).toBeEnabled();
        });

        it('disables the test employee checkbox in read-only mode', () => {
            renderForm({ isReadOnly: true });

            expect(screen.getByLabelText('Test employee')).toBeDisabled();
        });
    });

    describe('external profile links', () => {
        it('builds a facebook url from a bare username', () => {
            renderForm();

            expect(screen.getAllByRole('link').map((a) => a.getAttribute('href'))).toContain(
                'https://facebook.com/john.doe',
            );
        });

        it('keeps an absolute facebook url as-is', () => {
            renderForm({ user: buildUser({ info: { ...buildUser().info, facebook_link: 'https://fb.com/x' } }) });

            expect(screen.getAllByRole('link').map((a) => a.getAttribute('href'))).toContain('https://fb.com/x');
        });

        it('builds a LINE url from a bare username', () => {
            renderForm();

            expect(screen.getAllByRole('link').map((a) => a.getAttribute('href'))).toContain(
                'https://line.me/ti/p/~johnline',
            );
        });

        it('does not double the tilde when the LINE id already has one', () => {
            renderForm({ user: buildUser({ info: { ...buildUser().info, line_link: '~tilde' } }) });

            expect(screen.getAllByRole('link').map((a) => a.getAttribute('href'))).toContain(
                'https://line.me/ti/p/~tilde',
            );
        });

        it('renders no link when the profile fields are empty', () => {
            renderForm({ user: buildUser({ info: { ...buildUser().info, facebook_link: '', line_link: '' } }) });

            expect(screen.queryAllByRole('link')).toHaveLength(0);
        });
    });

    describe('read-only mode', () => {
        it('renders values as text instead of inputs', () => {
            renderForm({ isReadOnly: true });

            expect(screen.queryByLabelText('Last name')).not.toBeInTheDocument();
            expect(screen.getByText('DOE')).toBeInTheDocument();
            expect(screen.getByText('john@example.com')).toBeInTheDocument();
        });

        it('formats dates with slashes', () => {
            renderForm({ isReadOnly: true });

            expect(screen.getByText('1995/05/15')).toBeInTheDocument();
            expect(screen.getByText('2031/02/03')).toBeInTheDocument();
        });

        it('shows the latest residence card', () => {
            renderForm({ isReadOnly: true });

            expect(screen.getByText('AB1234567890')).toBeInTheDocument();
            expect(screen.getByText('2030/01/01')).toBeInTheDocument();
        });

        it('renders Yes for a household head', () => {
            renderForm({ isReadOnly: true });

            expect(screen.getByText('Yes')).toBeInTheDocument();
        });

        it('renders No when the user is not a household head', () => {
            renderForm({
                isReadOnly: true,
                user: buildUser({ info: { ...buildUser().info, is_household_head: false } }),
            });

            expect(screen.getByText('No')).toBeInTheDocument();
        });

        it('renders a dash when the household head flag is unknown', () => {
            renderForm({
                isReadOnly: true,
                user: buildUser({ info: { ...buildUser().info, is_household_head: null } }),
            });

            expect(screen.queryByText('Yes')).not.toBeInTheDocument();
            expect(screen.queryByText('No')).not.toBeInTheDocument();
            expect(screen.getAllByText('—').length).toBeGreaterThan(0);
        });
    });

    describe('alerts', () => {
        it('shows the approval alert when papers await approval and the admin can approve', () => {
            renderForm({ user: buildUser({ paper_status: 'wait_for_approval' }) });

            expect(screen.getByTestId('alert')).toHaveTextContent('User has uploaded personal documents');
        });

        it('hides the approval alert without USER_PAPER_APPROVE permission', () => {
            usePermissionsMock.mockReturnValue({ USER_EDIT: true, USER_PAPER_APPROVE: false });

            renderForm({ user: buildUser({ paper_status: 'wait_for_approval' }) });

            expect(screen.queryByTestId('alert')).not.toBeInTheDocument();
        });

        it('warns about an expiring passport', () => {
            renderForm({ user: buildUser({ passport_expiry_alert: true }) });

            expect(screen.getByTestId('alert')).toHaveTextContent(
                'Passport has expired or is about to expire, please update',
            );
        });

        it('warns about an expiring student card', () => {
            renderForm({ user: buildUser({ student_card_expiry_alert: true }) });

            expect(screen.getByTestId('alert')).toHaveTextContent(
                'Student card has expired or is about to expire, please update',
            );
        });

        it('shows no expiry warnings by default', () => {
            renderForm();

            expect(screen.queryByTestId('alert')).not.toBeInTheDocument();
        });
    });

    describe('study abroad', () => {
        it('does not require the student card expiry date for a non study-abroad status', () => {
            renderForm();

            expect(screen.getByLabelText('Student card expiry date')).toHaveAttribute('data-asterisk', 'no');
            expect(screen.getByLabelText('Student card expiry date')).toHaveAttribute('data-clearable', 'yes');
        });

        it('requires the student card expiry date when the latest residence status is study abroad', async () => {
            renderForm({
                user: buildUser({
                    info: { ...buildUser().info, residence_status_1: 'study_abroad' },
                }),
            });

            await waitFor(() =>
                expect(screen.getByLabelText('Student card expiry date')).toHaveAttribute('data-asterisk', 'yes'),
            );
            expect(screen.getByLabelText('Student card expiry date')).toHaveAttribute('data-clearable', 'no');
        });

        it('blocks submit when study abroad has no student card expiry date', async () => {
            const user = userEvent.setup();
            renderForm({
                user: buildUser({
                    info: { ...buildUser().info, residence_status_1: 'study_abroad', student_card_expiry_date: null },
                }),
            });

            await submitForm(user);

            await waitFor(() => expect(updateUserMock).not.toHaveBeenCalled());
        });

        it('allows submit when study abroad has a student card expiry date', async () => {
            const user = userEvent.setup();
            renderForm({
                user: buildUser({
                    info: {
                        ...buildUser().info,
                        residence_status_1: 'study_abroad',
                        student_card_expiry_date: '2030-06-06',
                    },
                }),
            });

            await submitForm(user);

            await waitFor(() => expect(updateUserMock).toHaveBeenCalledTimes(1));
        });

        it('picks the residence card with the latest expiry date for the study-abroad check', async () => {
            renderForm({
                user: buildUser({
                    info: {
                        ...buildUser().info,
                        residence_status_1: 'engineer',
                        residence_expiry_date_1: '2028-01-01',
                        residence_number_2: 'CD1234567890',
                        residence_expiry_date_2: '2032-01-01',
                        residence_status_2: 'study_abroad',
                    },
                }),
            });

            await waitFor(() =>
                expect(screen.getByLabelText('Student card expiry date')).toHaveAttribute('data-asterisk', 'yes'),
            );
        });
    });

    describe('validation', () => {
        it('does not submit when a required name is cleared', async () => {
            const user = userEvent.setup();
            renderForm();

            await user.clear(screen.getByLabelText('Last name'));
            await submitForm(user);

            await waitFor(() => expect(updateUserMock).not.toHaveBeenCalled());
        });

        it('does not submit with an invalid email', async () => {
            const user = userEvent.setup();
            renderForm();

            await user.clear(screen.getByLabelText('Email'));
            await user.type(screen.getByLabelText('Email'), 'not-an-email');
            await submitForm(user);

            await waitFor(() => expect(updateUserMock).not.toHaveBeenCalled());
        });
    });

    describe('submitting', () => {
        it('sends the transformed values and notifies the parent on success', async () => {
            const user = userEvent.setup();
            renderForm();

            await submitForm(user);

            await waitFor(() => expect(updateUserMock).toHaveBeenCalledTimes(1));
            expect(toParamsMock).toHaveBeenCalled();
            expect(updateUserMock.mock.calls[0][0]).toMatchObject({ email: 'john@example.com', staff_no: 'S100' });
            expect(onSaved).toHaveBeenCalledTimes(1);
        });

        it('leaves the staff number out while it is disabled', async () => {
            // A form opened before approval: saving it must not send the empty number back
            const user = userEvent.setup();
            renderForm({ user: buildUser({ paper_status: 'wait_for_approval', staff_no: null }) });

            await submitForm(user);

            await waitFor(() => expect(updateUserMock).toHaveBeenCalledTimes(1));
            expect(updateUserMock.mock.calls[0][0]).not.toHaveProperty('staff_no');
            expect(updateUserMock.mock.calls[0][0]).toMatchObject({ email: 'john@example.com' });
        });

        it('leaves the staff number out without USER_EDIT permission', async () => {
            usePermissionsMock.mockReturnValue({ USER_EDIT: false, USER_PAPER_APPROVE: true });
            const user = userEvent.setup();
            renderForm();

            await submitForm(user);

            await waitFor(() => expect(updateUserMock).toHaveBeenCalledTimes(1));
            expect(updateUserMock.mock.calls[0][0]).not.toHaveProperty('staff_no');
        });

        it('shows a dedicated error for the test-membership mismatch', async () => {
            const user = userEvent.setup();
            updateUserMock.mockRejectedValue(new FakeApiError({ message: 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH' }));
            renderForm();

            await submitForm(user);

            await waitFor(() =>
                expect(showErrorMock).toHaveBeenCalledWith(
                    'error|Cannot change test status while workplaces of the opposite type are assigned',
                ),
            );
            expect(setFormErrorMock).not.toHaveBeenCalled();
            expect(onSaved).not.toHaveBeenCalled();
        });

        it('delegates any other error to the form error handler', async () => {
            const user = userEvent.setup();
            updateUserMock.mockRejectedValue(new Error('network'));
            renderForm();

            await submitForm(user);

            await waitFor(() => expect(setFormErrorMock).toHaveBeenCalledTimes(1));
            expect(showErrorMock).not.toHaveBeenCalled();
            expect(onSaved).not.toHaveBeenCalled();
        });

        it('reports the saving state to the parent', () => {
            isPendingRef.current = true;

            renderForm();

            expect(onSavingChange).toHaveBeenCalledWith(true);
        });
    });

    describe('postal code lookup', () => {
        it('fills prefecture and address from the postal code on blur', async () => {
            const user = userEvent.setup();
            postalCodeGetMock.mockImplementation((_code: string, cb: (d: any) => void) =>
                cb({ prefecture: 'Osaka', city: 'Kita', area: 'Umeda', street: '1-1' }),
            );
            renderForm({ user: buildUser({ info: { ...buildUser().info, address: '' } }) });

            await user.click(screen.getByLabelText('Postal code'));
            await user.tab();

            await waitFor(() => expect(screen.getByLabelText('Prefecture')).toHaveValue('Osaka'));
            expect(screen.getByLabelText('Address')).toHaveValue('KitaUmeda1-1');
        });

        it('keeps an existing address when the postal code resolves', async () => {
            const user = userEvent.setup();
            postalCodeGetMock.mockImplementation((_code: string, cb: (d: any) => void) =>
                cb({ prefecture: 'Osaka', city: 'Kita', area: 'Umeda', street: '1-1' }),
            );
            renderForm();

            await user.click(screen.getByLabelText('Postal code'));
            await user.tab();

            await waitFor(() => expect(screen.getByLabelText('Prefecture')).toHaveValue('Osaka'));
            expect(screen.getByLabelText('Address')).toHaveValue('Chiyoda');
        });

        it('looks the postal code up with the current field value', async () => {
            const user = userEvent.setup();
            renderForm();

            await user.click(screen.getByLabelText('Postal code'));
            await user.tab();

            expect(postalCodeGetMock).toHaveBeenCalledWith('1000001', expect.any(Function));
        });

        it('hands the current address and prefecture to the reverse lookup and fills the code it returns', async () => {
            const user = userEvent.setup();
            renderForm();

            const button = screen.getByLabelText('Find postal code from address');
            expect(button).toHaveAttribute('data-address', 'Chiyoda');
            expect(button).toHaveAttribute('data-prefecture', 'Tokyo');
            // Mantine disables pointer events on the right section by default, which would make the button dead
            expect(screen.getByLabelText('Postal code')).toHaveAttribute('data-right-section-pointer-events', 'auto');

            await user.click(button);

            await waitFor(() => expect(screen.getByLabelText('Postal code')).toHaveValue('3500235'));
        });
    });

    describe('imperative handle', () => {
        it('exposes resetForm which discards edits and restores the loaded user values', async () => {
            const user = userEvent.setup();
            const ref = createRef<UserFormHandle>();
            render(
                <UserForm
                    id="42"
                    user={buildUser()}
                    isLoading={false}
                    isReadOnly={false}
                    formId="user-form"
                    onSaved={onSaved}
                    onSavingChange={onSavingChange}
                    ref={ref}
                />,
            );

            await user.clear(screen.getByLabelText('Phone'));
            await user.type(screen.getByLabelText('Phone'), '000');
            expect(screen.getByLabelText('Phone')).toHaveValue('000');

            // resetDirty() after loading the user makes those values the reset baseline
            await act(async () => {
                ref.current!.resetForm();
            });

            expect(screen.getByLabelText('Phone')).toHaveValue('09011112222');
        });
    });
});
