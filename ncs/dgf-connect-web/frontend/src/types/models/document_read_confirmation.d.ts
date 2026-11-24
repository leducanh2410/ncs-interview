import { DocumentRequiredConfig } from '@/types/models/document_required_config';
import { User } from '@/types/models/user';

export interface DocumentReadConfirmation {
    id: number;
    user_id: number;
    document_required_config_id: number;
    ip_address: string;
    device_info: {
        model: string | null;
        os: string | null;
        app_version: string | null;
    } | null;
    user?: User;
    config?: DocumentRequiredConfig;
    created_at: string;
    updated_at: string;
}
