import { User } from '@/types/models/user';

export interface UserLockHistory {
    id: number;
    user_id: number;
    is_locked: boolean;
    reason: string | null;
    action_by: User | null;
    action_at: string;
}
