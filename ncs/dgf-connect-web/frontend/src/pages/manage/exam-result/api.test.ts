import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';
import { exportExamResult } from '@/pages/manage/exam-result/api';

vi.mock('@/helpers/api.helper', () => ({
    default: { downloadBinary: vi.fn() },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { now: vi.fn() },
}));

const downloadBinaryMock = vi.mocked(ApiHelper.downloadBinary);
const nowMock = vi.mocked(DateHelper.now);

describe('exportExamResult', () => {
    let clickSpy: ReturnType<typeof vi.fn>;
    let removeSpy: ReturnType<typeof vi.fn>;
    let anchor: HTMLAnchorElement;
    let createObjectURLSpy: ReturnType<typeof vi.fn>;
    let revokeObjectURLSpy: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        clickSpy = vi.fn();
        removeSpy = vi.fn();
        anchor = { href: '', download: '', click: clickSpy, remove: removeSpy } as unknown as HTMLAnchorElement;
        vi.spyOn(document, 'createElement').mockReturnValue(anchor);

        createObjectURLSpy = vi.fn().mockReturnValue('blob:mock-url');
        revokeObjectURLSpy = vi.fn();
        // jsdom does not implement the blob URL API.
        Object.assign(URL, { createObjectURL: createObjectURLSpy, revokeObjectURL: revokeObjectURLSpy });

        nowMock.mockReturnValue({ format: () => '20260728153000' } as unknown as ReturnType<typeof DateHelper.now>);
        downloadBinaryMock.mockResolvedValue(new Blob(['csv'], { type: 'text/csv' }));
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('requests the export endpoint with the given filters as params', async () => {
        const params = { status: 'completed', title: 'Exam' };

        await exportExamResult(params);

        expect(downloadBinaryMock).toHaveBeenCalledWith(ApiConfig.MANAGE_EXAMS_RESULT_EXPORT, { params });
    });

    it('names the downloaded file exam_result_<timestamp>.csv and triggers the download', async () => {
        await exportExamResult({});

        expect(anchor.href).toBe('blob:mock-url');
        expect(anchor.download).toBe('exam_result_20260728153000.csv');
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(removeSpy).toHaveBeenCalledTimes(1);
    });

    it('creates a blob URL and always revokes it afterwards', async () => {
        const blob = new Blob(['csv'], { type: 'text/csv' });
        downloadBinaryMock.mockResolvedValue(blob);

        await exportExamResult({});

        expect(createObjectURLSpy).toHaveBeenCalledWith(blob);
        expect(revokeObjectURLSpy).toHaveBeenCalledWith('blob:mock-url');
    });

    it('propagates a download failure without creating or revoking a blob URL', async () => {
        downloadBinaryMock.mockRejectedValue(new Error('network'));

        await expect(exportExamResult({})).rejects.toThrow('network');
        expect(createObjectURLSpy).not.toHaveBeenCalled();
        expect(revokeObjectURLSpy).not.toHaveBeenCalled();
        expect(clickSpy).not.toHaveBeenCalled();
    });
});
