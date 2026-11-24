import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DateHelper from '@/helpers/date.helper';
import {
    exportSalaryRequest,
    useApproveSalaryRequestMutation,
    useConfirmPaidSalaryRequestMutation,
    useCreateSalaryRequestMutation,
    useGetSalaryRequestDetailQuery,
    useGetSalaryRequestQuery,
    useGetUserSalaryInfo,
    useImportSalaryDeductionMutation,
    useImportSalaryRemainingMutation,
    useImportSalaryTransferMutation,
    useRejectSalaryRequestMutation,
} from '@/pages/manage/salary-request/api';

const { get, post, postForm, downloadBinary } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    postForm: vi.fn(),
    downloadBinary: vi.fn(),
}));

vi.mock('@/helpers/api.helper', () => ({
    default: { get, post, postForm, downloadBinary },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { now: vi.fn() },
}));

const nowMock = vi.mocked(DateHelper.now);

const REQUEST_ID = '42';

let queryClient: QueryClient;
let invalidateSpy: ReturnType<typeof vi.spyOn>;

const wrapper = ({ children }: { children: React.ReactNode }) =>
    React.createElement(QueryClientProvider, { client: queryClient }, children);

const invalidatedKeys = () => invalidateSpy.mock.calls.map((call: any[]) => JSON.stringify(call[0]?.queryKey));

const expectInvalidated = (key: unknown[]) => expect(invalidatedKeys()).toContain(JSON.stringify(key));

beforeEach(() => {
    vi.clearAllMocks();
    queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    invalidateSpy = vi.spyOn(queryClient, 'invalidateQueries');
    get.mockResolvedValue({ error: null, data: { data: [], total: 0 } });
    post.mockResolvedValue({ error: null, data: null });
    postForm.mockResolvedValue({ error: null, data: null });
});

describe('salary request list & detail', () => {
    it('useGetSalaryRequestQuery is disabled without the list permission', () => {
        renderHook(() => useGetSalaryRequestQuery({ status: 'all' }, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetSalaryRequestQuery fetches the list with the current filters', async () => {
        const { result } = renderHook(() => useGetSalaryRequestQuery({ status: 'paid', user_id: '7' }, true), {
            wrapper,
        });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('manage/salary/requests', { params: { status: 'paid', user_id: '7' } });
    });

    it('useGetSalaryRequestDetailQuery is disabled without the list permission', () => {
        renderHook(() => useGetSalaryRequestDetailQuery(REQUEST_ID, false), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('useGetSalaryRequestDetailQuery fetches the detail url with the id substituted in', async () => {
        const { result } = renderHook(() => useGetSalaryRequestDetailQuery(REQUEST_ID, true), { wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(get).toHaveBeenCalledWith('manage/salary/requests/42');
    });
});

describe('useGetUserSalaryInfo', () => {
    /**
     * The salary range is only quoted when the admin explicitly asks for it, so this query
     * never runs on mount - the create modal drives it with refetch().
     */
    it('never fetches on its own', () => {
        renderHook(() => useGetUserSalaryInfo('7'), { wrapper });

        expect(get).not.toHaveBeenCalled();
    });

    it('sends the employee id as a query param when refetched', async () => {
        const { result } = renderHook(() => useGetUserSalaryInfo('7'), { wrapper });

        await result.current.refetch();

        expect(get).toHaveBeenCalledWith('manage/salary/info', { params: { user_id: '7' } });
    });
});

describe('salary request decisions', () => {
    it('useCreateSalaryRequestMutation posts the payload and refreshes the list', async () => {
        const { result } = renderHook(() => useCreateSalaryRequestMutation(), { wrapper });

        await result.current.mutateAsync({ user_id: '7', amount: 51000, type: 'transfer' });

        expect(post).toHaveBeenCalledWith('manage/salary/requests', {
            user_id: '7',
            amount: 51000,
            type: 'transfer',
        });
        await waitFor(() => expectInvalidated(['salary-request']));
    });

    it('useApproveSalaryRequestMutation posts an empty body and refreshes list + detail', async () => {
        const { result } = renderHook(() => useApproveSalaryRequestMutation(REQUEST_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('manage/salary/requests/42/approve', {});
        await waitFor(() => {
            expectInvalidated(['salary-request']);
            expectInvalidated(['salary-request', REQUEST_ID]);
        });
    });

    it('useRejectSalaryRequestMutation posts the reason and refreshes list + detail', async () => {
        const { result } = renderHook(() => useRejectSalaryRequestMutation(REQUEST_ID), { wrapper });

        await result.current.mutateAsync('Requested too early');

        expect(post).toHaveBeenCalledWith('manage/salary/requests/42/reject', { reason: 'Requested too early' });
        await waitFor(() => {
            expectInvalidated(['salary-request']);
            expectInvalidated(['salary-request', REQUEST_ID]);
        });
    });

    it('useConfirmPaidSalaryRequestMutation posts an empty body and refreshes list + detail', async () => {
        const { result } = renderHook(() => useConfirmPaidSalaryRequestMutation(REQUEST_ID), { wrapper });

        await result.current.mutateAsync();

        expect(post).toHaveBeenCalledWith('manage/salary/requests/42/confirm-paid', {});
        await waitFor(() => {
            expectInvalidated(['salary-request']);
            expectInvalidated(['salary-request', REQUEST_ID]);
        });
    });

    it('leaves the cache alone when a decision failed', async () => {
        post.mockRejectedValue(new Error('boom'));
        const { result } = renderHook(() => useApproveSalaryRequestMutation(REQUEST_ID), { wrapper });

        await expect(result.current.mutateAsync()).rejects.toThrow('boom');
        expect(invalidatedKeys()).not.toContain(JSON.stringify(['salary-request']));
    });
});

describe('CSV imports', () => {
    it.each([
        ['deduction', useImportSalaryDeductionMutation, 'manage/salary/import-deduction'],
        ['transfer', useImportSalaryTransferMutation, 'manage/salary/import-transfer'],
        ['remaining', useImportSalaryRemainingMutation, 'manage/salary/import-remaining'],
    ])('the %s import posts the form data to its own endpoint and refreshes the list', async (_name, hook, url) => {
        const formData = new FormData();
        formData.append('encoding', 'utf-8');
        const { result } = renderHook(() => (hook as () => any)(), { wrapper });

        await result.current.mutateAsync(formData);

        expect(postForm).toHaveBeenCalledWith(url, formData);
        await waitFor(() => expectInvalidated(['salary-request']));
    });
});

describe('exportSalaryRequest', () => {
    let anchor: HTMLAnchorElement;
    let clickSpy: ReturnType<typeof vi.fn>;
    let createObjectURLSpy: ReturnType<typeof vi.fn>;
    let revokeObjectURLSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        clickSpy = vi.fn();
        anchor = { href: '', download: '', click: clickSpy, remove: vi.fn() } as unknown as HTMLAnchorElement;
        vi.spyOn(document, 'createElement').mockReturnValue(anchor);

        createObjectURLSpy = vi.fn().mockReturnValue('blob:mock-url');
        revokeObjectURLSpy = vi.fn();
        Object.assign(URL, { createObjectURL: createObjectURLSpy, revokeObjectURL: revokeObjectURLSpy });

        nowMock.mockReturnValue({ format: () => '20260510120000' } as unknown as ReturnType<typeof DateHelper.now>);
        downloadBinary.mockResolvedValue(new Blob(['xlsx']));
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('requests the export endpoint with the current filters', async () => {
        const params = { status: 'wait_for_payment' };

        await exportSalaryRequest(params);

        expect(downloadBinary).toHaveBeenCalledWith('manage/salary/requests/export', { params });
    });

    it('names the file salary_request_<timestamp>.xlsx and revokes the blob URL', async () => {
        await exportSalaryRequest({});

        expect(anchor.download).toBe('salary_request_20260510120000.xlsx');
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-url');
    });

    it('propagates a download failure', async () => {
        downloadBinary.mockRejectedValue(new Error('network'));

        await expect(exportSalaryRequest({})).rejects.toThrow('network');
        expect(clickSpy).not.toHaveBeenCalled();
    });
});
