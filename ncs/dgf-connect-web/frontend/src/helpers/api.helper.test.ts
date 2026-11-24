import { afterEach, describe, expect, it, vi } from 'vitest';
import ApiHelper from './api.helper';

vi.mock('@/configs/api.config', () => ({
    ApiConfig: { BASE_URL: 'https://api.test' },
}));

vi.mock('@/helpers/common.helper', () => ({
    default: { urlQueryToString: vi.fn() },
}));

describe('ApiHelper.downloadBinary', () => {
    afterEach(() => {
        vi.mocked(localStorage.getItem).mockReset();
        vi.unstubAllGlobals();
    });

    it('sends the bearer token without JSON headers', async () => {
        vi.mocked(localStorage.getItem).mockReturnValue('token-123');
        const expectedBlob = new Blob(['image-bytes']);
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            blob: () => Promise.resolve(expectedBlob),
        });

        vi.stubGlobal('fetch', fetchMock);

        const blob = await ApiHelper.downloadBinary('chat/attachments/1/thumbnail');

        expect(blob).toBe(expectedBlob);
        expect(fetchMock).toHaveBeenCalledWith('https://api.test/chat/attachments/1/thumbnail', {
            method: 'GET',
            headers: {
                Authorization: 'Bearer token-123',
            },
            signal: undefined,
        });
    });
});

describe('ApiHelper.downloadBlob', () => {
    it('saves the blob through a temporary anchor and releases the object url', () => {
        const blob = new Blob(['xlsx']);
        URL.createObjectURL = vi.fn(() => 'blob:report');
        URL.revokeObjectURL = vi.fn();
        let downloadName = '';
        const clickSpy = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (
            this: HTMLAnchorElement,
        ) {
            downloadName = this.download;
        });

        ApiHelper.downloadBlob(blob, 'report.xlsx');

        expect(URL.createObjectURL).toHaveBeenCalledWith(blob);
        expect(clickSpy).toHaveBeenCalledTimes(1);
        expect(downloadName).toBe('report.xlsx');
        expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:report');
    });
});
