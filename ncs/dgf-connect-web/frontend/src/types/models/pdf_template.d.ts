import { CsvExportBatchMeta, ExportBatchType } from '@/types/models/csv_export';
import { ExcelExportBatchMeta } from '@/types/models/pdf_export_excel';
import { User } from '@/types/models/user';

export type PdfTemplateFieldShape = 'rectangle' | 'circle';

export type PdfTemplateFieldFillMode = 'text' | 'shape';

export type PdfTemplateTextFontWeight = 'normal' | 'bold';

export type PdfTemplateTextFontStyle = 'normal' | 'italic';

export type PdfTemplateTextHorizontalAlign = 'left' | 'center' | 'right';

export type PdfTemplateTextVerticalAlign = 'top' | 'middle' | 'bottom';

export type PdfTemplateTextOverflow = 'wrap' | 'trim' | 'shrink';

export type PdfTemplateShapeFillStyle = 'filled' | 'outlined';

export type PdfTemplateLockReason = 'editing' | 'exporting';

export type ExportBatchStatus = 'pending' | 'processing' | 'completed' | 'failed';

export type ExportBatchSource = 'manual' | 'excel';

export type ExportBatchMeta = CsvExportBatchMeta & ExcelExportBatchMeta;

export type ExportBatchItemStatus = 'pending' | 'completed' | 'failed';

export type DataSourceType = 'model_field' | 'fixed' | 'custom_function';

export interface TextConfig {
    font_weight: PdfTemplateTextFontWeight;
    font_style: PdfTemplateTextFontStyle;
    font_size: number;
    h_align: PdfTemplateTextHorizontalAlign;
    v_align: PdfTemplateTextVerticalAlign;
    letter_spacing: number | 'auto';
    overflow: PdfTemplateTextOverflow;
}

export interface ShapeConfig {
    fill_style: PdfTemplateShapeFillStyle;
    color: string;
    stroke_width: number | null;
}

export interface PdfTemplateField {
    id: number;
    pdf_template_id: number;
    page: number;
    shape: PdfTemplateFieldShape;
    x: number;
    y: number;
    width: number;
    height: number;
    data_source_key: string;
    placeholder: string | null;
    invert_value: boolean;
    fill_mode: PdfTemplateFieldFillMode;
    text_config: TextConfig | null;
    shape_config: ShapeConfig | null;
    created_at: string;
    updated_at: string;
}

export interface PdfTemplateLockInfo {
    user_id: number;
    reason: PdfTemplateLockReason;
    user_name: string;
    locked_at: string;
    ttl: number;
}

export interface PdfTemplate {
    id: number;
    name: string;
    file_path: string;
    file_name: string;
    page_count: number;
    field_count: number;
    lock_info: PdfTemplateLockInfo | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export interface PdfTemplateDetail extends PdfTemplate {
    file_url: string;
    fields: PdfTemplateField[];
}

export interface ExportBatch {
    id: number;
    type: ExportBatchType;
    source?: ExportBatchSource;
    pdf_template_id: number | null;
    exported_by: number;
    status: ExportBatchStatus;
    zip_file_paths: string[] | null;
    file_path: string | null;
    error_message: string | null;
    meta: ExportBatchMeta | null;
    total_count: number;
    completed_count: number;
    failed_count: number;
    created_at: string;
    updated_at: string;
    pdf_template?: PdfTemplate;
    exporter?: Pick<User, 'id' | 'name'>;
    users?: Pick<User, 'id' | 'name' | 'staff_no'>[];
}

export interface ExportBatchDetail extends ExportBatch {
    pdf_template: PdfTemplate;
    exporter: Pick<User, 'id' | 'name'>;
}

export interface ExportBatchItem {
    id: number;
    export_batch_id: number;
    user_id: number;
    status: ExportBatchItemStatus;
    file_path: string | null;
    file_name: string | null;
    error_message: string | null;
    created_at: string;
    updated_at: string;
    user?: Pick<User, 'id' | 'name' | 'staff_no' | 'profile_picture'>;
}

export interface DataSource {
    key: string;
    name: string;
    placeholder: string;
    type: DataSourceType;
    group: string;
    is_reversible?: boolean;
}
