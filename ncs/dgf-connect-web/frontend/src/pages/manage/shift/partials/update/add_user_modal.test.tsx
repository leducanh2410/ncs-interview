import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UserWithAbsenceCount } from '@/types/models/user';
import AddUserModal from '@/pages/manage/shift/partials/update/add_user_modal';

const { apiHelperGetMock, apiHelperPostMock } = vi.hoisted(() => ({
    apiHelperGetMock: vi.fn(),
    apiHelperPostMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { 'type': 'button', onClick, disabled, 'aria-label': ariaLabel }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Divider: () => React.createElement('hr', null),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    MultiSelect: ({ data, value, onChange, placeholder, disabled }: any) =>
        React.createElement(
            'div',
            { 'role': 'group', 'aria-label': placeholder },
            (data ?? []).map((opt: any) =>
                React.createElement(
                    'button',
                    {
                        'key': opt.value,
                        'type': 'button',
                        disabled,
                        'aria-pressed': value.includes(opt.value),
                        'onClick': () => {
                            const next = value.includes(opt.value)
                                ? value.filter((v: string) => v !== opt.value)
                                : [...value, opt.value];
                            onChange(next);
                        },
                    },
                    opt.label,
                ),
            ),
        ),
    ScrollArea: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', {
                type: 'checkbox',
                role: 'switch',
                checked,
                onChange,
            }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ value, onChange, onKeyDown, placeholder }: any) =>
        React.createElement('input', {
            'type': 'text',
            'aria-label': placeholder,
            value,
            onChange,
            onKeyDown,
        }),
    Tooltip: ({ children, label }: any) => React.createElement('div', { title: label }, children),
}));

vi.mock('@/helpers/api.helper', () => ({
    default: { get: apiHelperGetMock, post: apiHelperPostMock },
}));

vi.mock('@/pages/manage/shift/partials/absence_warning', () => ({
    default: ({ absenceCount }: { absenceCount: number }) =>
        absenceCount >= 1 ? React.createElement('span', { title: 'absence-warning' }, absenceCount) : null,
}));

vi.mock('@/utils/group_display_name', () => ({
    getGroupDisplayName: (group: { name: string }) => group.name,
}));

const buildUser = (overrides: Partial<UserWithAbsenceCount> = {}): UserWithAbsenceCount =>
    ({
        id: 1,
        staff_no: '001',
        name: 'Yamada Taro',
        email: 'yamada@example.com',
        paper_status: 'approved',
        residence_card_expiry_alert: false,
        student_card_expiry_alert: false,
        passport_expiry_alert: false,
        absence_count: 0,
        similar_shift_count: 0,
        ...overrides,
    }) as UserWithAbsenceCount;

describe('AddUserModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        apiHelperPostMock.mockResolvedValue({ data: [] });
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') {
                return Promise.resolve({ data: { data: [{ id: 1, name: 'Warehouse team' }] } });
            }
            return Promise.resolve({ data: [] });
        });
    });

    const baseProps = {
        isOpen: true,
        onClose: vi.fn(),
        onAddUser: vi.fn(),
        shiftId: '10',
        workplaceId: '5',
        workplaceShiftId: '10',
    };

    it('renders nothing when isOpen is false', () => {
        const { container } = render(<AddUserModal {...baseProps} isOpen={false} />);

        expect(container.firstChild).toBeNull();
    });

    it('fetches groups when opened and populates the group multiselect', async () => {
        render(<AddUserModal {...baseProps} />);

        expect(apiHelperGetMock).toHaveBeenCalledWith(
            'admin/groups',
            expect.objectContaining({ params: { per_page: '9999' } }),
        );
        expect(await screen.findByRole('button', { name: 'Warehouse team' })).toBeInTheDocument();
    });

    it('shows "No search performed yet" before any search and disables Search when input is too short', () => {
        render(<AddUserModal {...baseProps} />);

        expect(screen.getByText('No search performed yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Search' })).toBeDisabled();
    });

    it('enables Search once at least 3 characters are typed', async () => {
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'abc');

        expect(screen.getByRole('button', { name: 'Search' })).not.toBeDisabled();
    });

    it('enables Search when a group is selected even with no text', async () => {
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.click(await screen.findByRole('button', { name: 'Warehouse team' }));

        expect(screen.getByRole('button', { name: 'Search' })).not.toBeDisabled();
    });

    it('searches with the workplace, workplace shift, query, and group params, and renders results', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            if (url === 'manage/shifts/users/search') {
                return Promise.resolve({ data: [buildUser()] });
            }
            return Promise.resolve({ data: [] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(apiHelperGetMock).toHaveBeenCalledWith(
            'manage/shifts/users/search',
            expect.objectContaining({
                params: { workplace_id: '5', workplace_shift_id: '10', q: 'yamada' },
            }),
        );
        expect(await screen.findByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByText('1 users found')).toBeInTheDocument();
    });

    const buildWorkLoad = (overrides: Record<string, unknown> = {}) => ({
        user_id: 1,
        weekly_hours: 18,
        shift_hours: 9,
        is_shift_counted: false,
        projected_hours: 27,
        max_hour_per_week: 28,
        remaining_hours: 1,
        status: 'near',
        is_blocked: false,
        blocked_over_hours: null,
        is_custom_limit: false,
        limit_start_date: null,
        limit_expiry_date: null,
        window_start: '2026-09-03',
        window_end: '2026-09-09',
        days: [],
        ...overrides,
    });

    const mockSearchWithWorkLoad = (workLoad: ReturnType<typeof buildWorkLoad>) => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            if (url === 'manage/shifts/users/search') return Promise.resolve({ data: [buildUser()] });
            return Promise.resolve({ data: [] });
        });
        apiHelperPostMock.mockImplementation((url: string) =>
            Promise.resolve({ data: url === 'manage/shifts/10/weekly-work-load' ? [workLoad] : [] }),
        );
    };

    it('tells the admin how long this shift is', () => {
        render(<AddUserModal {...baseProps} shiftHours={8} />);

        expect(
            screen.getByText('This shift: 8h. Weekly hours are shown as current → after adding this shift / limit.'),
        ).toBeInTheDocument();
    });

    it('shows the weekly hours the shift would bring a searched user to', async () => {
        mockSearchWithWorkLoad(buildWorkLoad());
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('18h → 27h / 28h')).toBeInTheDocument();
        expect(apiHelperPostMock).toHaveBeenCalledWith(
            'manage/shifts/10/weekly-work-load',
            { user_ids: ['1'] },
            expect.objectContaining({ abortSignal: expect.anything() }),
        );
        expect(screen.getByRole('button', { name: 'Add' })).not.toBeDisabled();
    });

    it('blocks adding a user whom this shift would push over the weekly limit', async () => {
        mockSearchWithWorkLoad(
            buildWorkLoad({
                weekly_hours: 27,
                projected_hours: 36,
                remaining_hours: -8,
                status: 'over',
                is_blocked: true,
                blocked_over_hours: 8,
            }),
        );
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('27h → 36h / 28h')).toBeInTheDocument();
        expect(screen.getByTitle('Cannot assign: the weekly work-hour limit would be exceeded')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
    });

    it('does not auto-add the only match when it is over the weekly limit', async () => {
        mockSearchWithWorkLoad(buildWorkLoad({ is_blocked: true, blocked_over_hours: 8, status: 'over' }));
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), '001');
        await user.keyboard('{Control>}{Enter}{/Control}');

        expect(await screen.findByRole('button', { name: 'Add' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
    });

    it('still lists search results when the weekly hours lookup fails', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            if (url === 'manage/shifts/users/search') return Promise.resolve({ data: [buildUser()] });
            return Promise.resolve({ data: [] });
        });
        apiHelperPostMock.mockRejectedValue(new Error('network'));
        const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add' })).not.toBeDisabled();
        expect(
            screen.getByText('Could not load weekly hours. Check the weekly limit before adding.'),
        ).toBeInTheDocument();
        consoleErrorSpy.mockRestore();
    });

    it('still auto-adds the only match when its weekly hours could not be checked', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            if (url === 'manage/shifts/users/search') return Promise.resolve({ data: [buildUser()] });
            return Promise.resolve({ data: [] });
        });
        apiHelperPostMock.mockRejectedValue(new Error('network'));
        const consoleErrorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), '001');
        await user.keyboard('{Control>}{Enter}{/Control}');

        expect(await screen.findByRole('button', { name: 'Remove' })).toBeInTheDocument();
        expect(
            screen.getByText('Could not load weekly hours. Check the weekly limit before adding.'),
        ).toBeInTheDocument();
        consoleErrorSpy.mockRestore();
    });

    it('shows "No results" when the search returns an empty list', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'nobody');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByText('No results')).toBeInTheDocument();
    });

    it('moves a searched user into the selected list when Add is clicked, and hides it from the results', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser()] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await screen.findByText('Yamada Taro');

        await user.click(screen.getByRole('button', { name: 'Add' }));

        expect(screen.getByText('No results')).toBeInTheDocument();
        expect(screen.getByText('Yamada Taro')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Remove' })).toBeInTheDocument();
    });

    it('removes a user from the selected list when Remove is clicked', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser()] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await user.click(await screen.findByRole('button', { name: 'Add' }));

        await user.click(screen.getByRole('button', { name: 'Remove' }));

        expect(screen.getByText('No users selected')).toBeInTheDocument();
    });

    it('disables the Add action and shows a red warning tooltip for a user with rejected papers', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser({ paper_status: 'rejected' })] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await screen.findByText('Yamada Taro');

        expect(screen.getByRole('button', { name: 'Add' })).toBeDisabled();
        expect(screen.getByTitle('Paper has been rejected')).toBeInTheDocument();
    });

    it('shows an incomplete documents badge for a pending user and still allows adding them', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser({ paper_status: 'pending' })] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await screen.findByText('Yamada Taro');

        expect(screen.getByText('Documents incomplete')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add' })).toBeEnabled();
    });

    it('shows the absence warning for a user with recent absences', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser({ absence_count: 2 })] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));

        expect(await screen.findByTitle('absence-warning')).toHaveTextContent('2');
    });

    it('runs an exact staff-no search and auto-adds the sole eligible result on Ctrl+Enter', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser()] });
        });
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        const input = screen.getByLabelText('Search user by staff no or email');
        await user.type(input, '001');
        await user.keyboard('{Control>}{Enter}{/Control}');

        await waitFor(() =>
            expect(apiHelperGetMock).toHaveBeenCalledWith(
                'manage/shifts/users/search',
                expect.objectContaining({ params: expect.objectContaining({ staff_no: '001' }) }),
            ),
        );
        expect(await screen.findByRole('button', { name: 'Remove' })).toBeInTheDocument();
    });

    it('toggles the Fixed/Temp switch label', async () => {
        const user = userEvent.setup();
        render(<AddUserModal {...baseProps} />);

        expect(screen.getByText('Temp')).toBeInTheDocument();

        await user.click(screen.getByRole('switch'));

        expect(screen.getByText('Fixed')).toBeInTheDocument();
    });

    it('disables Save until a user is selected', () => {
        render(<AddUserModal {...baseProps} />);

        expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled();
    });

    it('calls onAddUser with selected users and isFixed, then closes on Save', async () => {
        apiHelperGetMock.mockImplementation((url: string) => {
            if (url === 'admin/groups') return Promise.resolve({ data: { data: [] } });
            return Promise.resolve({ data: [buildUser()] });
        });
        const user = userEvent.setup();
        const onAddUser = vi.fn();
        const onClose = vi.fn();
        render(<AddUserModal {...baseProps} onAddUser={onAddUser} onClose={onClose} />);

        await user.type(screen.getByLabelText('Search user by staff no or email'), 'yamada');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await user.click(await screen.findByRole('button', { name: 'Add' }));
        await user.click(screen.getByRole('switch'));

        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(onAddUser).toHaveBeenCalledWith([expect.objectContaining({ id: 1, name: 'Yamada Taro' })], true);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('calls onClose when Cancel is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        render(<AddUserModal {...baseProps} onClose={onClose} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});
