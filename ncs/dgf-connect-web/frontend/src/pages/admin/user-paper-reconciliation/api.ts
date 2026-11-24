import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { PaginatedData } from '@/types/api';
import {
    ReconciliationDetail,
    ReconciliationFilterOptions,
    ReconciliationRow,
} from '@/types/models/paper_reconciliation';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import CommonHelper from '@/helpers/common.helper';

export const useGetPaperReconciliationQuery = (params: ParsedUrlQuery) => {
    return useQuery({
        queryKey: ['admin', 'paper-reconciliation', params],
        queryFn: () =>
            ApiHelper.get<PaginatedData<ReconciliationRow>>(ApiConfig.ADMIN_PAPER_RECONCILIATION, { params }),
    });
};

export const useGetPaperReconciliationDetailQuery = (userId: number | null) => {
    return useQuery({
        queryKey: ['admin', 'paper-reconciliation', 'detail', userId],
        // The popup opens on one call; it never fans out into several requests.
        queryFn: () =>
            ApiHelper.get<ReconciliationDetail>(
                CommonHelper.replaceUrlParam(ApiConfig.ADMIN_PAPER_RECONCILIATION_DETAIL, {
                    id: String(userId),
                }),
            ),
        enabled: userId !== null,
    });
};

/**
 * What the filter bar can offer: the workplaces always, and the shifts that actually ran
 * once a workplace and a day are both picked. Served here rather than from the workplace
 * administration list, which the reconciliation permission does not open.
 */
export const useGetReconciliationFilterOptionsQuery = (workplaceId?: string, date?: string) => {
    // Half a pair is a 422: the backend takes both or neither.
    const shiftParams = workplaceId && date ? { workplace_id: workplaceId, date } : {};

    return useQuery({
        queryKey: ['admin', 'paper-reconciliation', 'filter-options', workplaceId, date],
        queryFn: () =>
            ApiHelper.get<ReconciliationFilterOptions>(ApiConfig.ADMIN_PAPER_RECONCILIATION_FILTER_OPTIONS, {
                params: shiftParams,
            }),
    });
};
