import { User } from '@/types/models/user';

export type UserFace = {
    id: number;
    user_id: number;
    user_paper_item_id: number;
    vector_id: string;
    provider: string;
    created_at: string;
    updated_at: string;
    user?: User;
};
