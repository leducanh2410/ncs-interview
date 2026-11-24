import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    useAddWorkplacesMutation,
    useApplyUserPapersOcrMutation,
    useApproveUserPaperMutation,
    useAssignRoleMutation,
    useCreateManagerMutation,
    useDeleteDeviceMutation,
    useDeleteWorkplaceMutation,
    useDisableUserMutation,
    useEnableUserMutation,
    useExtractUserPapersOcrMutation,
    useGetUserConfigsQuery,
    useGetUserDevicesQuery,
    useGetUserLockHistoriesQuery,
    useGetUserPapersQuery,
    useGetUserQuery,
    useGetUserRolesQuery,
    useGetUserUpcomingShiftsQuery,
    useGetUserWorkplacesQuery,
    useGetUsersQuery,
    useInitEmployeeMutation,
    useMoveUserPaperMutation,
    useRegisterEmployeeMutation,
    useRemoveRoleMutation,
    useReopenEmptiedPaperStepsMutation,
    useResetPasswordMutation,
    useRestoreUserMutation,
    useRestoreUserPaperMutation,
    useSetEmployeePasswordMutation,
    useSyncUserFromCrossStaffMutation,
    useSyncUserToCrossStaffMutation,
    useUpdateUserConfigsMutation,
    useUpdateUserMutation,
    useUploadImageMutation,
} from './api';

const { get, post, postForm, patch, put, del } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    postForm: vi.fn(),
    patch: vi.fn(),
    put: vi.fn(),
    del: vi.fn(),
}));

vi.mock('@/helpers/api.helper', () => {
    class ApiError extends Error {}
    return { ApiError, default: { get, post, postForm, patch, put, delete: del } };
});

const USER_ID = '42';

let queryClient: QueryClient;
let invalidateSpy: ReturnType<typeof vi.spyOn>;

const wrapper = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);

/** Query keys passed to invalidateQueries across all calls, flattened for easier assertions. */
const invalidatedKeys = () => invalidateSpy.mock.calls.map((call: any[]) => JSON.stringify(call[0]?.queryKey));

const expectInvalidated = (key: unknown[]) => expect(invalidatedKeys()).toContain(JSON.stringify(key));

beforeEach(() => {
    vi.clearAllMocks();
    queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    get.mockResolvedValue({ error: null, data: null });
    post.mockResolvedValue({ error: null, data: null });
    postForm.mockResolvedValue({ error: null, data: null });
    patch.mockResolvedValue({ error: null, data: null });
    put.mockResolvedValue({ error: null, data: null });
    del.mockResolvedValue({ error: null, data: null });
});

describe('user list & creation', () => {
    it('useGetUsersQuery is disabled without permission', () => {
        renderHook(() => useGetUsersQuery({ page: '1' }, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUsersQuery fetches the user list with the given params when permitted', async () => {
        const { result } = renderHook(() => useGetUsersQuery({ page: '2' }, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users', { params: { page: '2' } });
    });

    it('useCreateManagerMutation posts to the user list and invalidates users', async () => {
        const { result } = renderHook(() => useCreateManagerMutation(), { wrapper });

        await result.current.mutateAsync({ email: 'a@b.c' });

        expect(post).toHaveBeenCalledWith('admin/users', { email: 'a@b.c' });
        await waitFor(() => expectInvalidated(['users']));
    });
});

describe('employee registration flow', () => {
    it('useInitEmployeeMutation posts to the init endpoint', async () => {
        const { result } = renderHook(() => useInitEmployeeMutation(), { wrapper });

        await result.current.mutateAsync({ email: 'a@b.c' });

        expect(post).toHaveBeenCalledWith('admin/users/employees/init', { email: 'a@b.c' });
    });

    it('useSetEmployeePasswordMutation posts to the set-password endpoint', async () => {
        const { result } = renderHook(() => useSetEmployeePasswordMutation(), { wrapper });

        await result.current.mutateAsync({ key: 'k', password: 'p' });

        expect(post).toHaveBeenCalledWith('admin/users/employees/set-password', { key: 'k', password: 'p' });
    });

    it('useRegisterEmployeeMutation posts to the register endpoint and invalidates users', async () => {
        const { result } = renderHook(() => useRegisterEmployeeMutation(), { wrapper });

        await result.current.mutateAsync({ key: 'k' });

        expect(post).toHaveBeenCalledWith('admin/users/employees/register', { key: 'k' });
        await waitFor(() => expectInvalidated(['users']));
    });
});

describe('user detail', () => {
    it('useGetUserQuery fetches the user detail url', async () => {
        const { result } = renderHook(() => useGetUserQuery(USER_ID), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42');
    });

    /**
     * The papers tab is invalidated too: it reads the profile for the student card expiry
     * date, this form is where that date is written, and the tab is not remounted when the
     * admin returns to it. Without this the warning and the disabled Submit survive the fix
     * for the whole staleTime, and the admin who did the right thing is told it did nothing.
     */
    it('useUpdateUserMutation patches the detail url and invalidates list + detail + papers', async () => {
        const { result } = renderHook(() => useUpdateUserMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ first_name: 'New' });

        expect(patch).toHaveBeenCalledWith('admin/users/42', { first_name: 'New' });
        await waitFor(() => {
            expectInvalidated(['users']);
            expectInvalidated(['user', USER_ID]);
            expectInvalidated(['user-papers', USER_ID]);
        });
    });
});

describe('disable / enable / restore / reset password', () => {
    it('useDisableUserMutation posts the reason and invalidates lock history and groups', async () => {
        const { result } = renderHook(() => useDisableUserMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ reason: 'left' });

        expect(post).toHaveBeenCalledWith('admin/users/42/disable', { reason: 'left' });
        await waitFor(() => {
            expectInvalidated(['user-lock-history', USER_ID]);
            expectInvalidated(['user-groups', USER_ID]);
            expectInvalidated(['groups']);
        });
    });

    it('useEnableUserMutation posts the reason and invalidates lock history', async () => {
        const { result } = renderHook(() => useEnableUserMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ reason: 'appeal accepted' });

        expect(post).toHaveBeenCalledWith('admin/users/42/enable', { reason: 'appeal accepted' });
        await waitFor(() => expectInvalidated(['user-lock-history', USER_ID]));
    });

    it.each([
        ['useDisableUserMutation', useDisableUserMutation, 'USER_ALREADY_DISABLED'],
        ['useEnableUserMutation', useEnableUserMutation, 'USER_ALREADY_ENABLED'],
    ])('%s refreshes the account state when it was already changed elsewhere', async (_, useHook, code) => {
        const { ApiError } = await import('@/helpers/api.helper');
        post.mockRejectedValue(new (ApiError as any)(code));
        const { result } = renderHook(() => useHook(USER_ID), { wrapper });

        await expect(result.current.mutateAsync({ reason: 'stale' })).rejects.toBeInstanceOf(ApiError);

        await waitFor(() => {
            expectInvalidated(['user', USER_ID]);
            expectInvalidated(['user-lock-history', USER_ID]);
            expectInvalidated(['users']);
        });
    });

    it.each([
        ['useDisableUserMutation', useDisableUserMutation],
        ['useEnableUserMutation', useEnableUserMutation],
    ])('%s does not refresh anything on an unrelated failure', async (_, useHook) => {
        const { ApiError } = await import('@/helpers/api.helper');
        post.mockRejectedValue(new (ApiError as any)('E_INTERNAL_SERVER_ERROR'));
        const { result } = renderHook(() => useHook(USER_ID), { wrapper });

        await expect(result.current.mutateAsync({ reason: 'x' })).rejects.toBeInstanceOf(ApiError);

        expect(invalidateSpy).not.toHaveBeenCalled();
    });

    it('useRestoreUserMutation posts a null body to the restore url', async () => {
        const { result } = renderHook(() => useRestoreUserMutation(USER_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('admin/users/42/restore', null);
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });

    it('useResetPasswordMutation posts the new password', async () => {
        const { result } = renderHook(() => useResetPasswordMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ password: 'secret' });

        expect(post).toHaveBeenCalledWith('admin/users/42/reset-password', { password: 'secret' });
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });

    it('useGetUserLockHistoriesQuery fetches the lock history url', async () => {
        const { result } = renderHook(() => useGetUserLockHistoriesQuery(USER_ID), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/lock-histories');
    });

    it('useGetUserUpcomingShiftsQuery does not fetch on mount', () => {
        renderHook(() => useGetUserUpcomingShiftsQuery(USER_ID), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUserUpcomingShiftsQuery fetches the upcoming shifts url on refetch', async () => {
        get.mockResolvedValue({ error: null, data: [{ id: 7 }] });
        const { result } = renderHook(() => useGetUserUpcomingShiftsQuery(USER_ID), { wrapper });

        const refetched = await result.current.refetch();

        expect(get).toHaveBeenCalledWith('admin/users/42/upcoming-shifts');
        expect(refetched.data?.data).toEqual([{ id: 7 }]);
    });

    it('useGetUserUpcomingShiftsQuery reports refetch failures as an error result', async () => {
        get.mockRejectedValue(new Error('network down'));
        const { result } = renderHook(() => useGetUserUpcomingShiftsQuery(USER_ID), { wrapper });

        const refetched = await result.current.refetch();

        expect(refetched.isError).toBe(true);
        expect(refetched.data).toBeUndefined();
    });
});

describe('images', () => {
    it('useUploadImageMutation posts multipart form data', async () => {
        const { result } = renderHook(() => useUploadImageMutation(USER_ID), { wrapper });
        const formData = new FormData();

        await result.current.mutateAsync(formData);

        expect(postForm).toHaveBeenCalledWith('admin/users/42/images', formData);
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });
});

describe('devices', () => {
    it('useGetUserDevicesQuery is disabled without permission', () => {
        renderHook(() => useGetUserDevicesQuery(USER_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUserDevicesQuery fetches the device list when permitted', async () => {
        const { result } = renderHook(() => useGetUserDevicesQuery(USER_ID, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/devices');
    });

    it('useDeleteDeviceMutation deletes the nested device url and invalidates devices only', async () => {
        const { result } = renderHook(() => useDeleteDeviceMutation(USER_ID), { wrapper });

        await result.current.mutateAsync('9');

        expect(del).toHaveBeenCalledWith('admin/users/42/devices/9');
        await waitFor(() => expectInvalidated(['user-devices', USER_ID]));
        expect(invalidatedKeys()).not.toContain(JSON.stringify(['users']));
    });
});

describe('workplaces', () => {
    it('useGetUserWorkplacesQuery is disabled without permission', () => {
        renderHook(() => useGetUserWorkplacesQuery(USER_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUserWorkplacesQuery fetches the workplace list when permitted', async () => {
        const { result } = renderHook(() => useGetUserWorkplacesQuery(USER_ID, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/workplaces');
    });

    it('useAddWorkplacesMutation posts the ids under workplace_ids', async () => {
        const { result } = renderHook(() => useAddWorkplacesMutation(USER_ID), { wrapper });

        await result.current.mutateAsync(['1', '2']);

        expect(post).toHaveBeenCalledWith('admin/users/42/workplaces', { workplace_ids: ['1', '2'] });
        await waitFor(() => expectInvalidated(['user-workplaces', USER_ID]));
    });

    it('useDeleteWorkplaceMutation deletes the nested workplace url', async () => {
        const { result } = renderHook(() => useDeleteWorkplaceMutation(USER_ID), { wrapper });

        await result.current.mutateAsync('3');

        expect(del).toHaveBeenCalledWith('admin/users/42/workplaces/3');
        await waitFor(() => expectInvalidated(['user-workplaces', USER_ID]));
    });
});

describe('roles', () => {
    it('useGetUserRolesQuery is disabled without permission', () => {
        renderHook(() => useGetUserRolesQuery(USER_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUserRolesQuery fetches the role list when permitted', async () => {
        const { result } = renderHook(() => useGetUserRolesQuery(USER_ID, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/roles');
    });

    it('useAssignRoleMutation posts the role payload', async () => {
        const { result } = renderHook(() => useAssignRoleMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ role_name: 'admin', workplace_id: 5 });

        expect(post).toHaveBeenCalledWith('admin/users/42/roles', { role_name: 'admin', workplace_id: 5 });
        await waitFor(() => expectInvalidated(['user-roles', USER_ID]));
    });

    it('useRemoveRoleMutation sends the role as query params', async () => {
        const { result } = renderHook(() => useRemoveRoleMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ role_name: 'admin', workplace_id: 5 });

        expect(del).toHaveBeenCalledWith('admin/users/42/roles', {
            params: { role_name: 'admin', workplace_id: '5' },
        });
        await waitFor(() => expectInvalidated(['user-roles', USER_ID]));
    });

    it('useRemoveRoleMutation sends an empty workplace_id when it is null', async () => {
        const { result } = renderHook(() => useRemoveRoleMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ role_name: 'admin', workplace_id: null });

        expect(del).toHaveBeenCalledWith('admin/users/42/roles', {
            params: { role_name: 'admin', workplace_id: '' },
        });
    });
});

describe('papers', () => {
    it('useGetUserPapersQuery fetches papers by default', async () => {
        const { result } = renderHook(() => useGetUserPapersQuery(USER_ID), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/papers');
    });

    it('useGetUserPapersQuery is disabled when permission is false', () => {
        renderHook(() => useGetUserPapersQuery(USER_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useApproveUserPaperMutation posts items and rejected note', async () => {
        const { result } = renderHook(() => useApproveUserPaperMutation(USER_ID), { wrapper });
        const payload = { items: [{ id: 1, approved: true }], rejected_note: 'blurry' };

        await result.current.mutateAsync(payload);

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/approve', payload);
        await waitFor(() => expectInvalidated(['user-papers', USER_ID]));
    });

    it('useMoveUserPaperMutation posts ids and folder', async () => {
        const { result } = renderHook(() => useMoveUserPaperMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ ids: [1, 2], folder: 'archive' });

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/move', { ids: [1, 2], folder: 'archive' });
        await waitFor(() => expectInvalidated(['user-papers', USER_ID]));
    });

    it('useReopenEmptiedPaperStepsMutation posts to the reopen url and refreshes the user', async () => {
        const { result } = renderHook(() => useReopenEmptiedPaperStepsMutation(USER_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/reopen-emptied-steps', null);
        await waitFor(() => expectInvalidated(['user-papers', USER_ID]));
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });

    it('useRestoreUserPaperMutation posts ids to the restore url', async () => {
        const { result } = renderHook(() => useRestoreUserPaperMutation(USER_ID), { wrapper });

        await result.current.mutateAsync([3]);

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/restore', { ids: [3] });
        await waitFor(() => expectInvalidated(['user-papers', USER_ID]));
    });

    it('useExtractUserPapersOcrMutation posts types and invalidates nothing', async () => {
        const { result } = renderHook(() => useExtractUserPapersOcrMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ types: ['passport'] });

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/ocr', { types: ['passport'] });
        expect(invalidateSpy).not.toHaveBeenCalled();
    });

    it('useApplyUserPapersOcrMutation posts selected fields and invalidates user data', async () => {
        const { result } = renderHook(() => useApplyUserPapersOcrMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ ocr_result_id: 7, fields: ['passport_number'] });

        expect(post).toHaveBeenCalledWith('admin/users/42/papers/ocr/apply', {
            ocr_result_id: 7,
            fields: ['passport_number'],
        });
        await waitFor(() => expectInvalidated(['user', USER_ID]));
        await waitFor(() => expectInvalidated(['user-papers', USER_ID]));
    });
});

describe('cross staff sync', () => {
    it('useSyncUserToCrossStaffMutation posts a null body', async () => {
        const { result } = renderHook(() => useSyncUserToCrossStaffMutation(USER_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('admin/users/42/to-cross-staff', null);
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });

    it('useSyncUserFromCrossStaffMutation posts a null body', async () => {
        const { result } = renderHook(() => useSyncUserFromCrossStaffMutation(USER_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('admin/users/42/from-cross-staff', null);
        await waitFor(() => expectInvalidated(['user', USER_ID]));
    });
});

describe('user configs', () => {
    it('useGetUserConfigsQuery is disabled without permission', () => {
        renderHook(() => useGetUserConfigsQuery(USER_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetUserConfigsQuery fetches the config url when permitted', async () => {
        const { result } = renderHook(() => useGetUserConfigsQuery(USER_ID, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('admin/users/42/configs');
    });

    it('useUpdateUserConfigsMutation puts the config payload', async () => {
        const { result } = renderHook(() => useUpdateUserConfigsMutation(USER_ID), { wrapper });

        await result.current.mutateAsync({ is_active: 'true' });

        expect(put).toHaveBeenCalledWith('admin/users/42/configs', { is_active: 'true' });
        await waitFor(() => expectInvalidated(['user-config', USER_ID]));
    });
});
