import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    useCreateSalaryProcessMutation,
    useDeleteSalaryProcessMutation,
    useGetSalaryProcessesQuery,
    useUpdateSalaryProcessMutation,
} from '@/pages/manage/salary/process/api';

const { get, post, put, del } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    del: vi.fn(),
}));

vi.mock('@/helpers/api.helper', () => ({
    default: { get, post, put, delete: del },
}));

let queryClient: QueryClient;
let invalidateSpy: ReturnType<typeof vi.spyOn>;

const wrapper = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);

const invalidatedKeys = () => invalidateSpy.mock.calls.map((call: any[]) => JSON.stringify(call[0]?.queryKey));

beforeEach(() => {
    vi.clearAllMocks();
    queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    get.mockResolvedValue({ error: null, data: { data: [], total: 0 } });
    post.mockResolvedValue({ error: null, data: null });
    put.mockResolvedValue({ error: null, data: null });
    del.mockResolvedValue({ error: null, data: null });
});

describe('useGetSalaryProcessesQuery', () => {
    it('does not fetch anything without the process list permission', () => {
        renderHook(() => useGetSalaryProcessesQuery({ month: '2026-03' }, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('fetches the schedule list with the current filters', async () => {
        const { result } = renderHook(() => useGetSalaryProcessesQuery({ workplace_id: '3' }, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('manage/salary/processes', { params: { workplace_id: '3' } });
    });
});

describe('salary process mutations', () => {
    it('useCreateSalaryProcessMutation posts the payload and refreshes the list', async () => {
        const { result } = renderHook(() => useCreateSalaryProcessMutation(), { wrapper });

        await result.current.mutateAsync({ month: '2026-03', workplace_id: '3', priority: 5 });

        expect(post).toHaveBeenCalledWith('manage/salary/processes', {
            month: '2026-03',
            workplace_id: '3',
            priority: 5,
        });
        await waitFor(() => expect(invalidatedKeys()).toContain(JSON.stringify(['salary-process'])));
    });

    it('useUpdateSalaryProcessMutation puts to the schedule url with the id substituted in', async () => {
        const { result } = renderHook(() => useUpdateSalaryProcessMutation(), { wrapper });

        await result.current.mutateAsync({ id: 12, data: { priority: 9 } });

        expect(put).toHaveBeenCalledWith('manage/salary/processes/12', { priority: 9 });
        await waitFor(() => expect(invalidatedKeys()).toContain(JSON.stringify(['salary-process'])));
    });

    it('useDeleteSalaryProcessMutation deletes the schedule url and refreshes the list', async () => {
        const { result } = renderHook(() => useDeleteSalaryProcessMutation(), { wrapper });

        await result.current.mutateAsync(7);

        expect(del).toHaveBeenCalledWith('manage/salary/processes/7');
        await waitFor(() => expect(invalidatedKeys()).toContain(JSON.stringify(['salary-process'])));
    });

    it('does not refresh the list when the write failed', async () => {
        post.mockRejectedValue(new Error('boom'));
        const { result } = renderHook(() => useCreateSalaryProcessMutation(), { wrapper });

        await expect(result.current.mutateAsync({ month: '2026-03' })).rejects.toThrow('boom');
        expect(invalidatedKeys()).not.toContain(JSON.stringify(['salary-process']));
    });
});
