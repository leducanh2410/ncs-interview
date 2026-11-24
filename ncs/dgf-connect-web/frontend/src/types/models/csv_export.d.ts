import { ExportBatchStatus } from '@/types/models/pdf_template';
import { User } from '@/types/models/user';

export type ExportBatchType = 'pdf' | 'csv' | 'work_history';

export interface CsvPropertyMeta {
    key: string;
    label_ja: string;
}

export interface CsvExportBatchMeta {
    selected_keys?: string[];
    ordered_keys?: string[];
    export_keys?: string[];
    encoding?: string;
    user_ids?: number[];
    select_all?: boolean;
    filters?: Record<string, string>;
    conditions?: Record<string, unknown>;
}

export interface CsvExportBatch {
    id: number;
    type: ExportBatchType;
    status: ExportBatchStatus;
    exported_by: number;
    file_path: string | null;
    error_message: string | null;
    total_count: number;
    completed_count: number;
    failed_count: number;
    meta: CsvExportBatchMeta | null;
    created_at: string;
    updated_at: string;
    exporter?: Pick<User, 'id' | 'name'>;
}
