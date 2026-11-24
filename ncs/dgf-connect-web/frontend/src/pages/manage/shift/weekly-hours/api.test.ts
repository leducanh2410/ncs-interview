import { beforeEach, describe, expect, it, vi } from 'vitest';
import { exportWeeklyWorkHours } from '@/pages/manage/shift/weekly-hours/api';

const { downloadBinaryMock, downloadBlobMock } = vi.hoisted(() => ({
    downloadBinaryMock: vi.fn(),
    downloadBlobMock: vi.fn(),
}));

vi.mock('@/helpers/api.helper', () => ({
    default: {
        downloadBinary: (...args: unknown[]) => downloadBinaryMock(...args),
        downloadBlob: (...args: unknown[]) => downloadBlobMock(...args),
        get: vi.fn(),
    },
}));

describe('exportWeeklyWorkHours', () => {
    const blob = new Blob(['xlsx']);

    beforeEach(() => {
        vi.clearAllMocks();
        downloadBinaryMock.mockResolvedValue(blob);
    });

    it('downloads the current filters without the paging parameters and names the file by the range', async () => {
        await exportWeeklyWorkHours({
            dates: ['2026-09-01', '2026-09-07'],
            load_status: 'over',
            staff_no: '10',
            page: '3',
            page_size: '20',
        });

        expect(downloadBinaryMock).toHaveBeenCalledWith('manage/shifts/weekly-work-hours/export', {
            params: { dates: ['2026-09-01', '2026-09-07'], load_status: 'over', staff_no: '10' },
        });
        expect(downloadBlobMock).toHaveBeenCalledWith(blob, 'weekly_work_hours_2026-09-01_2026-09-07.xlsx');
    });
});
