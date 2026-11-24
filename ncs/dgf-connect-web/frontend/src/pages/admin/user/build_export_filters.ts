import { ParsedUrlQuery } from 'querystring';
import type { UserExportFilters } from '@/types/user_export';

const toArray = (value: string | string[] | undefined): string[] => {
    if (value === undefined) {
        return [];
    }

    return Array.isArray(value) ? value : [value];
};

export const buildUserExportFilters = (query: ParsedUrlQuery): UserExportFilters => ({
    staff_no: (query.staff_no as string) || '',
    staff_no_empty: (query.staff_no_empty as string) || '',
    name: (query.name as string) || '',
    email: (query.email as string) || '',
    paper_status: (query.paper_status as string) || 'all',
    workplace_id: (query.workplace_id as string) || '0',
    workplace_none: (query.workplace_none as string) || '',
    is_disabled: (query.is_disabled as string) || '0',
    role: (query.role as string) || 'all',
    face_registered: (query.face_registered as string) || 'all',
    custom_config: (query.custom_config as string) || 'all',
    info_search_field: (query.info_search_field as string) || '',
    info_search_value: (query.info_search_value as string) || '',
    groups: toArray(query.groups),
});
