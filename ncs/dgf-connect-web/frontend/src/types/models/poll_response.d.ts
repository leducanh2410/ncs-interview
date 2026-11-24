import { Poll } from '@/types/models/poll';
import { User } from '@/types/models/user';
import { WorkplaceSimple } from '@/types/models/workplace';

export interface PollResponse {
    id: number;
    user_id: number;
    poll_id: number;
    workplace_id: number | null;
    occurrence: number;
    workplace?: WorkplaceSimple | null;
    answer: Record<string, 1 | -1 | 0> | string[];
    ip_address: string;
    device_info: {
        model?: string;
        os?: string;
        app_version?: string;
    } | null;
    user?: User;
    poll?: Poll;
    created_at: string;
    updated_at: string;
}
