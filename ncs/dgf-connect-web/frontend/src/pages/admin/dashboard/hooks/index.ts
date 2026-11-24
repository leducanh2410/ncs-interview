import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import {
    CapacityResponse,
    DashboardWorkplacesResponse,
    HeatmapMode,
    HeatmapResponse,
    KpiResponse,
    ReliabilityResponse,
    WindowSelection,
} from '@/pages/admin/dashboard/types';

const buildParams = (input: {
    selection?: WindowSelection;
    workplace_ids?: number[];
    mode?: HeatmapMode;
}): ParsedUrlQuery => {
    const out: ParsedUrlQuery = {};
    if (input.selection !== undefined) {
        if (input.selection.kind === 'preset') {
            out.window = String(input.selection.days);
        } else {
            out.start_date = input.selection.startDate;
            out.end_date = input.selection.endDate;
        }
    }
    if (input.mode !== undefined) {
        out.mode = input.mode;
    }
    if (input.workplace_ids !== undefined && input.workplace_ids.length > 0) {
        out.workplace_ids = input.workplace_ids.map(String);
    }
    return out;
};

const selectionKey = (selection: WindowSelection): readonly unknown[] =>
    selection.kind === 'preset'
        ? (['preset', selection.days] as const)
        : (['custom', selection.startDate, selection.endDate] as const);

export const useDashboardWorkplaces = (enabled: boolean = true) => {
    return useQuery({
        queryKey: ['dashboard', 'workplaces'],
        queryFn: async (): Promise<ApiResponse<DashboardWorkplacesResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD_WORKPLACES);
        },
        enabled,
    });
};

export const useDashboardKpis = (workplaceIds: number[], selection: WindowSelection, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['dashboard', 'kpis', workplaceIds, ...selectionKey(selection)],
        queryFn: async (): Promise<ApiResponse<KpiResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD_KPIS, {
                params: buildParams({ selection, workplace_ids: workplaceIds }),
            });
        },
        enabled,
    });
};

export const useCapacityTable = (workplaceIds: number[], enabled: boolean = true) => {
    return useQuery({
        queryKey: ['dashboard', 'capacity', workplaceIds],
        queryFn: async (): Promise<ApiResponse<CapacityResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD_CAPACITY, {
                params: buildParams({ workplace_ids: workplaceIds }),
            });
        },
        enabled,
    });
};

export const useDemandHeatmap = (
    workplaceIds: number[],
    selection: WindowSelection,
    mode: HeatmapMode,
    enabled: boolean = true,
) => {
    return useQuery({
        queryKey: ['dashboard', 'demand-heatmap', workplaceIds, ...selectionKey(selection), mode],
        queryFn: async (): Promise<ApiResponse<HeatmapResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD_DEMAND_HEATMAP, {
                params: buildParams({ selection, mode, workplace_ids: workplaceIds }),
            });
        },
        enabled,
    });
};

export const useShowUpRate = (workplaceIds: number[], selection: WindowSelection, enabled: boolean = true) => {
    return useQuery({
        queryKey: ['dashboard', 'show-up-rate', workplaceIds, ...selectionKey(selection)],
        queryFn: async (): Promise<ApiResponse<ReliabilityResponse>> => {
            return ApiHelper.get(ApiConfig.ADMIN_DASHBOARD_SHOW_UP_RATE, {
                params: buildParams({ selection, workplace_ids: workplaceIds }),
            });
        },
        enabled,
    });
};

export const useClearDashboardCache = (workplaceIds: number[]) => {
    const queryClient = useQueryClient();
    return useMutation({
        mutationFn: async (): Promise<ApiResponse<boolean>> => {
            return ApiHelper.post(ApiConfig.ADMIN_DASHBOARD_CLEAR_CACHE, buildParams({ workplace_ids: workplaceIds }));
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['dashboard'] }),
    });
};
