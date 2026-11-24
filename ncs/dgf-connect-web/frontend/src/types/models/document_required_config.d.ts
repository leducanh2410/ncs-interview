import { Document } from '@/types/models/document';
import { Workplace } from '@/types/models/workplace';

export interface DocumentRequiredConfig {
    id: number;
    scope: 'system' | 'workplace';
    workplace_id: number | null;
    document_id: number | null;
    title_vi: string;
    title_en: string;
    title_ja: string;
    title_ne?: string | null;
    title_si?: string | null;
    is_active: boolean;
    created_by: number;
    document?: Document;
    workplace?: Workplace;
    created_at: string;
    updated_at: string;
}
