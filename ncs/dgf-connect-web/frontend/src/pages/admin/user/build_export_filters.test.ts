import { describe, expect, it } from 'vitest';
import { buildUserExportFilters } from './build_export_filters';

describe('buildUserExportFilters', () => {
    it('forwards the info search filters applied to the user list', () => {
        const filters = buildUserExportFilters({
            info_search_field: 'passport_number',
            info_search_value: 'PASS123',
        });

        expect(filters.info_search_field).toBe('passport_number');
        expect(filters.info_search_value).toBe('PASS123');
    });

    it('forwards the group filter as an array', () => {
        expect(buildUserExportFilters({ groups: ['1', '2'] }).groups).toEqual(['1', '2']);
    });

    it('normalizes a single group value into an array', () => {
        expect(buildUserExportFilters({ groups: '7' }).groups).toEqual(['7']);
    });

    it('forwards the custom configuration filter', () => {
        expect(buildUserExportFilters({ custom_config: '1' }).custom_config).toBe('1');
    });

    it('defaults every filter when the query is empty', () => {
        expect(buildUserExportFilters({})).toEqual({
            staff_no: '',
            staff_no_empty: '',
            name: '',
            email: '',
            paper_status: 'all',
            workplace_id: '0',
            workplace_none: '',
            is_disabled: '0',
            role: 'all',
            face_registered: 'all',
            custom_config: 'all',
            info_search_field: '',
            info_search_value: '',
            groups: [],
        });
    });
});
