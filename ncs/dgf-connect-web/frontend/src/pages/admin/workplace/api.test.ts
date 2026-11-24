import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApiHelper from '@/helpers/api.helper';
import {
    useAddUsersToWorkplaceMutation,
    useCreateWorkplaceInstructionMutation,
    useCreateWorkplaceMutation,
    useCreateWorkplacePositionMutation,
    useCreateWorkplaceShiftMutation,
    useDeleteWorkplaceImageMutation,
    useDeleteWorkplaceInstructionMutation,
    useDeleteWorkplaceMutation,
    useDeleteWorkplacePositionMutation,
    useDeleteWorkplaceShiftMutation,
    useGetWorkplaceDetailQuery,
    useGetWorkplaceImagesQuery,
    useGetWorkplaceInstructionsQuery,
    useGetWorkplacePositionsQuery,
    useGetWorkplaceShiftsQuery,
    useGetWorkplaceUsersQuery,
    useGetWorkplacesQuery,
    useRemoveUserFromWorkplaceMutation,
    useReorderWorkplacePositionsMutation,
    useUpdateWorkplaceInstructionMutation,
    useUpdateWorkplaceMutation,
    useUpdateWorkplacePositionMutation,
    useUpdateWorkplaceShiftMutation,
    useUploadWorkplaceImagesMutation,
} from './api';

vi.mock('@/helpers/api.helper', () => ({
    default: {
        get: vi.fn().mockResolvedValue({ data: null }),
        post: vi.fn().mockResolvedValue({ data: null }),
        put: vi.fn().mockResolvedValue({ data: null }),
        delete: vi.fn().mockResolvedValue({ data: null }),
        postForm: vi.fn().mockResolvedValue({ data: null }),
    },
}));

const WORKPLACE_ID = '7';

let queryClient: QueryClient;

const wrapper = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);

beforeEach(() => {
    vi.clearAllMocks();
    queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
});

/** Renders a mutation hook, runs it once with the given variables, and waits for it to settle. */
const runMutation = async <TVariables>(
    useMutationHook: () => { mutateAsync: (variables: TVariables) => Promise<unknown> },
    variables: TVariables,
) => {
    const { result } = renderHook(useMutationHook, { wrapper });

    await act(() => result.current.mutateAsync(variables));
};

describe('workplace queries', () => {
    it('fetches the workplace list with the given query params', async () => {
        renderHook(() => useGetWorkplacesQuery({ page: '2', name: 'Osaka' }, true), { wrapper });

        await waitFor(() =>
            expect(ApiHelper.get).toHaveBeenCalledWith('admin/workplaces', {
                params: { page: '2', name: 'Osaka' },
            }),
        );
    });

    it('does not fetch the workplace list without the permission', async () => {
        renderHook(() => useGetWorkplacesQuery({}, false), { wrapper });

        await waitFor(() => expect(ApiHelper.get).not.toHaveBeenCalled());
    });

    it('fetches a workplace detail by id', async () => {
        renderHook(() => useGetWorkplaceDetailQuery(WORKPLACE_ID), { wrapper });

        await waitFor(() => expect(ApiHelper.get).toHaveBeenCalledWith('admin/workplaces/7'));
    });

    it.each([
        ['images', useGetWorkplaceImagesQuery, 'admin/workplaces/7/images'],
        ['positions', useGetWorkplacePositionsQuery, 'admin/workplaces/7/positions'],
        ['shifts', useGetWorkplaceShiftsQuery, 'admin/workplaces/7/shifts'],
        ['instructions', useGetWorkplaceInstructionsQuery, 'admin/workplaces/7/instructions'],
    ])('fetches workplace %s', async (_name, useQueryHook, expectedUrl) => {
        renderHook(() => (useQueryHook as (id: string, hasPermission: boolean) => unknown)(WORKPLACE_ID, true), {
            wrapper,
        });

        await waitFor(() => expect(ApiHelper.get).toHaveBeenCalledWith(expectedUrl));
    });

    it.each([
        ['images', useGetWorkplaceImagesQuery],
        ['positions', useGetWorkplacePositionsQuery],
        ['shifts', useGetWorkplaceShiftsQuery],
        ['instructions', useGetWorkplaceInstructionsQuery],
    ])('does not fetch workplace %s without the permission', async (_name, useQueryHook) => {
        renderHook(() => (useQueryHook as (id: string, hasPermission: boolean) => unknown)(WORKPLACE_ID, false), {
            wrapper,
        });

        await waitFor(() => expect(ApiHelper.get).not.toHaveBeenCalled());
    });

    it('does not fetch shifts without a workplace id', async () => {
        renderHook(() => useGetWorkplaceShiftsQuery('', true), { wrapper });

        await waitFor(() => expect(ApiHelper.get).not.toHaveBeenCalled());
    });

    it('fetches workplace users for the requested page', async () => {
        renderHook(() => useGetWorkplaceUsersQuery(WORKPLACE_ID, 3, true), { wrapper });

        await waitFor(() =>
            expect(ApiHelper.get).toHaveBeenCalledWith('admin/workplaces/7/users', { params: { page: '3' } }),
        );
    });

    it('does not fetch workplace users without a workplace id', async () => {
        renderHook(() => useGetWorkplaceUsersQuery('', 1, true), { wrapper });

        await waitFor(() => expect(ApiHelper.get).not.toHaveBeenCalled());
    });
});

describe('workplace mutations', () => {
    it('creates a workplace', async () => {
        await runMutation(useCreateWorkplaceMutation, { name: 'Osaka' });

        expect(ApiHelper.post).toHaveBeenCalledWith('admin/workplaces', { name: 'Osaka' });
    });

    it('updates a workplace', async () => {
        await runMutation(() => useUpdateWorkplaceMutation(WORKPLACE_ID), { name: 'Osaka' });

        expect(ApiHelper.put).toHaveBeenCalledWith('admin/workplaces/7', { name: 'Osaka' });
    });

    it('deletes a workplace', async () => {
        await runMutation(useDeleteWorkplaceMutation, WORKPLACE_ID);

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7');
    });

    it('invalidates the workplace lists after an update', async () => {
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries');

        await runMutation(() => useUpdateWorkplaceMutation(WORKPLACE_ID), { name: 'Osaka' });

        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['workplaces'] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['workplace', WORKPLACE_ID] });
        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['master/all'] });
    });
});

describe('workplace image mutations', () => {
    it('uploads images as multipart form data', async () => {
        const formData = new FormData();

        await runMutation(() => useUploadWorkplaceImagesMutation(WORKPLACE_ID), formData);

        expect(ApiHelper.postForm).toHaveBeenCalledWith('admin/workplaces/7/images', formData);
    });

    it('deletes an image by its id', async () => {
        await runMutation(() => useDeleteWorkplaceImageMutation(WORKPLACE_ID), '12');

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7/images/12');
    });

    it('invalidates the image list after an upload', async () => {
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries');

        await runMutation(() => useUploadWorkplaceImagesMutation(WORKPLACE_ID), new FormData());

        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['workplace-images', WORKPLACE_ID] });
    });
});

describe('workplace position mutations', () => {
    it('creates a position', async () => {
        await runMutation(() => useCreateWorkplacePositionMutation(WORKPLACE_ID), { name: 'Cashier' });

        expect(ApiHelper.post).toHaveBeenCalledWith('admin/workplaces/7/positions', { name: 'Cashier' });
    });

    it('updates a position', async () => {
        await runMutation(() => useUpdateWorkplacePositionMutation(WORKPLACE_ID), {
            positionId: '4',
            data: { name: 'Cashier' },
        });

        expect(ApiHelper.put).toHaveBeenCalledWith('admin/workplaces/7/positions/4', { name: 'Cashier' });
    });

    it('deletes a position', async () => {
        await runMutation(() => useDeleteWorkplacePositionMutation(WORKPLACE_ID), '4');

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7/positions/4');
    });

    it('reorders positions', async () => {
        await runMutation(() => useReorderWorkplacePositionsMutation(WORKPLACE_ID), [3, 1, 2]);

        expect(ApiHelper.put).toHaveBeenCalledWith('admin/workplaces/7/positions/reorder', {
            positions_order: [3, 1, 2],
        });
    });

    it('clears the whole cache after creating a position', async () => {
        const clear = vi.spyOn(queryClient, 'clear');

        await runMutation(() => useCreateWorkplacePositionMutation(WORKPLACE_ID), { name: 'Cashier' });

        expect(clear).toHaveBeenCalled();
    });

    it('only invalidates the position list after a reorder', async () => {
        const clear = vi.spyOn(queryClient, 'clear');
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries');

        await runMutation(() => useReorderWorkplacePositionsMutation(WORKPLACE_ID), [1]);

        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['workplace-positions', WORKPLACE_ID] });
        expect(clear).not.toHaveBeenCalled();
    });
});

describe('workplace shift mutations', () => {
    it('creates a shift', async () => {
        await runMutation(() => useCreateWorkplaceShiftMutation(WORKPLACE_ID), { start_time: '09:00' });

        expect(ApiHelper.post).toHaveBeenCalledWith('admin/workplaces/7/shifts', { start_time: '09:00' });
    });

    it('updates a shift', async () => {
        await runMutation(() => useUpdateWorkplaceShiftMutation(WORKPLACE_ID), {
            shiftId: '9',
            data: { start_time: '09:00' },
        });

        expect(ApiHelper.put).toHaveBeenCalledWith('admin/workplaces/7/shifts/9', { start_time: '09:00' });
    });

    it('deletes a shift', async () => {
        await runMutation(() => useDeleteWorkplaceShiftMutation(WORKPLACE_ID), '9');

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7/shifts/9');
    });
});

describe('workplace instruction mutations', () => {
    it('creates an instruction from a plain payload', async () => {
        await runMutation(() => useCreateWorkplaceInstructionMutation(WORKPLACE_ID), { type: 'link' });

        expect(ApiHelper.post).toHaveBeenCalledWith('admin/workplaces/7/instructions', { type: 'link' });
        expect(ApiHelper.postForm).not.toHaveBeenCalled();
    });

    it('creates an instruction from form data', async () => {
        const formData = new FormData();
        formData.append('type', 'image');

        await runMutation(() => useCreateWorkplaceInstructionMutation(WORKPLACE_ID), formData);

        expect(ApiHelper.postForm).toHaveBeenCalledWith('admin/workplaces/7/instructions', formData);
        expect(ApiHelper.post).not.toHaveBeenCalled();
    });

    it('updates an instruction from a plain payload', async () => {
        await runMutation(() => useUpdateWorkplaceInstructionMutation(WORKPLACE_ID), {
            instructionId: '5',
            data: { type: 'link' },
        });

        expect(ApiHelper.put).toHaveBeenCalledWith('admin/workplaces/7/instructions/5', { type: 'link' });
    });

    it('spoofs the PUT method when updating an instruction with form data', async () => {
        const formData = new FormData();
        formData.append('type', 'image');

        await runMutation(() => useUpdateWorkplaceInstructionMutation(WORKPLACE_ID), {
            instructionId: '5',
            data: formData,
        });

        expect(ApiHelper.postForm).toHaveBeenCalledWith('admin/workplaces/7/instructions/5', formData);
        expect(formData.get('_method')).toBe('PUT');
        expect(ApiHelper.put).not.toHaveBeenCalled();
    });

    it('deletes an instruction', async () => {
        await runMutation(() => useDeleteWorkplaceInstructionMutation(WORKPLACE_ID), '5');

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7/instructions/5');
    });
});

describe('workplace user mutations', () => {
    it('adds users to a workplace', async () => {
        await runMutation(() => useAddUsersToWorkplaceMutation(WORKPLACE_ID), ['1', '2']);

        expect(ApiHelper.post).toHaveBeenCalledWith('admin/workplaces/7/users', { user_ids: ['1', '2'] });
    });

    it('removes a user from a workplace', async () => {
        await runMutation(() => useRemoveUserFromWorkplaceMutation(WORKPLACE_ID), '11');

        expect(ApiHelper.delete).toHaveBeenCalledWith('admin/workplaces/7/users/11');
    });

    it('invalidates the workplace user list after adding users', async () => {
        const invalidateQueries = vi.spyOn(queryClient, 'invalidateQueries');

        await runMutation(() => useAddUsersToWorkplaceMutation(WORKPLACE_ID), ['1']);

        expect(invalidateQueries).toHaveBeenCalledWith({ queryKey: ['workplace-users', WORKPLACE_ID] });
    });
});
