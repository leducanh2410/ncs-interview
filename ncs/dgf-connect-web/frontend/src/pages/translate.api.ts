import { useMutation } from '@tanstack/react-query';
import { ApiResponse } from '@/types/api';
import { ApiConfig } from '@/configs/api.config';
import ApiHelper from '@/helpers/api.helper';

export type TranslateContext = 'notification' | 'poll' | 'document_read_config' | 'exam' | 'announcement';

export type TranslateItem = {
    key: string;
    text: string;
};

export type TranslateParams = {
    items: TranslateItem[];
    source_locale: string;
    target_locales: string[];
    context: TranslateContext;
};

// locale => { item key => translated text }
export type TranslationsMap = Record<string, Record<string, string>>;

export const useTranslateContentMutation = () => {
    return useMutation({
        mutationFn: async (params: TranslateParams): Promise<ApiResponse<{ translations: TranslationsMap }>> => {
            return ApiHelper.post(ApiConfig.ADMIN_TRANSLATE, params);
        },
    });
};
