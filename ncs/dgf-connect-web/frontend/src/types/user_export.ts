/**
 * Filters forwarded to the batch export endpoints when "select all matching filters" is used.
 *
 * Every field must mirror a filter applied to the user list query, otherwise the export runs
 * against a broader set of users than the list shows. Keep in sync with the backend rules in
 * `CreateCsvExportRequest` and `BatchExportRequest`.
 */
export type UserExportFilters = {
    staff_no: string;
    staff_no_empty: string;
    name: string;
    email: string;
    paper_status: string;
    workplace_id: string;
    workplace_none: string;
    is_disabled: string;
    role: string;
    face_registered: string;
    custom_config: string;
    info_search_field: string;
    info_search_value: string;
    groups: string[];
};
