import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export interface AbsentNoticeListItem {
    id: number;
    shift_id: number;
    shift_date: string;
    workplace_name: string;
    shift_time: string;
    user_name: string;
    position_name: string;
    requested_at: string;
    status: string;
    processed_by_name: string | null;
    processed_at: string | null;
}

const normalizeParams = (params: ParsedUrlQuery): ParsedUrlQuery => {
    const normalizedParams: ParsedUrlQuery = { ...params };
    const dates = normalizedParams.dates;

    delete normalizedParams.dates;

    if (Array.isArray(dates)) {
        if (dates[0]) {
            normalizedParams.date_from = dates[0];
        }
        if (dates[1]) {
            normalizedParams.date_to = dates[1];
        }
    } else if (dates) {
        normalizedParams.date_from = dates;
    }

    if (normalizedParams.workplace_id === '0') {
        delete normalizedParams.workplace_id;
    }

    if (normalizedParams.status === '') {
        delete normalizedParams.status;
    }

    return normalizedParams;
};

export const useGetAbsentNoticesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['absent-notices', params],
        queryFn: async (): Promise<ApiResponse<PaginatedData<AbsentNoticeListItem>>> => {
            return ApiHelper.get(ApiConfig.MANAGE_ABSENT_NOTICES, { params: normalizeParams(params) });
        },
        enabled: hasPermission,
    });
};

/**
 * Marking a request processed does not always remove the employee from the shift, and removing
 * them does not always notify them, so the API reports what it actually did and the screen repeats
 * that instead of describing the intended case.
 */
export type AbsentNoticeShiftOutcome =
    | 'shift_rejected'
    | 'shift_kept_already_started'
    | 'shift_kept_checked_in'
    | 'shift_unchanged';

export type UpdateAbsentNoticeStatusResponse = ApiResponse<unknown> & {
    shift_outcome?: AbsentNoticeShiftOutcome;
    notified?: boolean;
};

export const useUpdateAbsentNoticeStatusMutation = () => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async ({
            id,
            status,
        }: {
            id: number;
            status: string;
        }): Promise<UpdateAbsentNoticeStatusResponse> => {
            return ApiHelper.patch(
                CommonHelper.replaceUrlParam(ApiConfig.MANAGE_ABSENT_NOTICE_STATUS, { id: id.toString() }),
                { status },
            );
        },
        onSuccess: async () => {
            await queryClient.invalidateQueries({ queryKey: ['absent-notices'] });
        },
    });
};
