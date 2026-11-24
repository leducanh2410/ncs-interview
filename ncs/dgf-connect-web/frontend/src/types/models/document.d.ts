import { User } from '@/types/models/user';
import { Workplace } from '@/types/models/workplace';

export interface Document {
    id: number;
    title: string;
    content: string;
    users: User[];
    workplaces: Workplace[];
    attachments: {
        id: number;
        document_id: number;
        type: File;
        url: string;
        filename: string;
    }[];
    created_at: string;
    updated_at: string;
}
