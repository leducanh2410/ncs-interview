import { useQuery } from '@tanstack/react-query';
import { ParsedUrlQuery } from 'querystring';
import { ApiResponse, PaginatedData } from '@/types/api';
import { SalaryWithHours } from '@/types/models/salary';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';
import DateHelper from '@/helpers/date.helper';

type GetSalariesResponse = ApiResponse<PaginatedData<SalaryWithHours>>;

export const useGetSalariesQuery = (params: ParsedUrlQuery, hasPermission: boolean = false) => {
    return useQuery({
        queryKey: ['salary', params],
        queryFn: async (): Promise<GetSalariesResponse> => {
            return ApiHelper.get(ApiConfig.MANAGE_SALARY, { params });
        },
        enabled: hasPermission,
    });
};

export const exportSalary = async (params: ParsedUrlQuery) => {
    const blob = await ApiHelper.downloadBinary(ApiConfig.MANAGE_SALARY_EXPORT, { params });
    const objectUrl = URL.createObjectURL(blob);
    const link: HTMLAnchorElement = document.createElement('a');
    const now = DateHelper.now().format('YYYYMMDDHHmmss');
    link.href = objectUrl;
    link.download = `salary_${now}.xlsx`;
    link.click();
    link.remove();
    URL.revokeObjectURL(objectUrl);
};
