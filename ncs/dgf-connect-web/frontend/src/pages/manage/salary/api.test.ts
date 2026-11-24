import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DateHelper from '@/helpers/date.helper';
import { exportSalary, useGetSalariesQuery } from '@/pages/manage/salary/api';

const { get, downloadBinary } = vi.hoisted(() => ({
    get: vi.fn(),
    downloadBinary: vi.fn(),
}));

vi.mock('@/helpers/api.helper', () => ({
    default: { get, downloadBinary },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { now: vi.fn() },
}));

const nowMock = vi.mocked(DateHelper.now);

let queryClient: QueryClient;

const wrapper = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);

beforeEach(() => {
    vi.clearAllMocks();
    queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    get.mockResolvedValue({ error: null, data: { data: [], total: 0 } });
});

describe('useGetSalariesQuery', () => {
    it('does not fetch anything without the salary list permission', () => {
        renderHook(() => useGetSalariesQuery({ month: '2026-03' }, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('defaults to being disabled when no permission flag is passed', () => {
        renderHook(() => useGetSalariesQuery({ month: '2026-03' }), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('fetches the salary list with the current filters', async () => {
        const { result } = renderHook(() => useGetSalariesQuery({ month: '2026-03', user_id: '7' }, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('manage/salary', { params: { month: '2026-03', user_id: '7' } });
    });

    it('caches per filter set, so changing the month refetches', async () => {
        const { result, rerender } = renderHook(({ params }) => useGetSalariesQuery(params, true), {
            wrapper,
            initialProps: { params: { month: '2026-03' } as Record<string, string> },
        });
        await waitFor(() => expect(result.current.isSuccess).toBe(true));

        rerender({ params: { month: '2026-04' } });

        await waitFor(() => expect(get).toHaveBeenCalledTimes(2));
        expect(get).toHaveBeenLastCalledWith('manage/salary', { params: { month: '2026-04' } });
    });
});

describe('exportSalary', () => {
    let anchor: HTMLAnchorElement;
    let clickSpy: ReturnType<typeof vi.fn>;
    let removeSpy: ReturnType<typeof vi.fn>;
    let createObjectURLSpy: ReturnType<typeof vi.fn>;
    let revokeObjectURLSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        clickSpy = vi.fn();
        removeSpy = vi.fn();
        anchor = { href: '', download: '', click: clickSpy, remove: removeSpy } as unknown as HTMLAnchorElement;
        vi.spyOn(document, 'createElement').mockReturnValue(anchor);

        createObjectURLSpy = vi.fn().mockReturnValue('blob:mock-url');
        revokeObjectURLSpy = vi.fn();
        // jsdom does not implement the blob URL API.
        Object.assign(URL, { createObjectURL: createObjectURLSpy, revokeObjectURL: revokeObjectURLSpy });

        nowMock.mockReturnValue({ format: () => '20260510120000' } as unknown as ReturnType<typeof DateHelper.now>);
        downloadBinary.mockResolvedValue(new Blob(['xlsx']));
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('requests the export endpoint with the current filters', async () => {
        const params = { month: '2026-03', user_id: '7' };

        await exportSalary(params);

        expect(downloadBinary).toHaveBeenCalledWith('manage/salary/export', { params });
    });

    it('names the file salary_<timestamp>.xlsx and triggers the download', async () => {
        await exportSalary({});

        expect(anchor.href).toBe('blob:mock-url');
        expect(anchor.download).toBe('salary_20260510120000.xlsx');
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(removeSpy).toHaveBeenCalledTimes(1);
    });

    it('revokes the blob URL once the download has started', async () => {
        const blob = new Blob(['xlsx']);
        downloadBinary.mockResolvedValue(blob);

        await exportSalary({});

        expect(createObjectURLSpy).toHaveBeenCalledWith(blob);
        expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-url');
    });

    it('propagates a download failure without leaking a blob URL', async () => {
        downloadBinary.mockRejectedValue(new Error('network'));

        await expect(exportSalary({})).rejects.toThrow('network');
        expect(createObjectURLSpy).not.toHaveBeenCalled();
        expect(clickSpy).not.toHaveBeenCalled();
    });
});
