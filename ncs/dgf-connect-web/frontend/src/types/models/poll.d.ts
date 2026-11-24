import { User } from '@/types/models/user';
import { WorkplaceSimple } from '@/types/models/workplace';

export type PollTargetScope = 'global' | 'workplace' | 'specific_users';

export interface PollChoice {
    id: string;
    label_vi: string;
    label_en: string;
    label_ja: string;
    label_ne?: string | null;
    label_si?: string | null;
}

export interface VoteResultCounters {
    [userId: string]: {
        yes: number;
        no: number;
        blank: number;
    };
}

export interface FeedbackResultCounters {
    [choiceId: string]: number;
}

export interface Poll {
    id: number;
    type: 'vote' | 'feedback';
    target_type: PollTargetScope;
    workplace_id: number | null;
    target_users?: User[];
    title_vi: string;
    title_en: string;
    title_ja: string;
    title_ne?: string | null;
    title_si?: string | null;
    description_vi: string | null;
    description_en: string | null;
    description_ja: string | null;
    description_ne?: string | null;
    description_si?: string | null;
    is_active: boolean;
    is_required: boolean;
    is_recurring: boolean;
    available_occurrences?: number[];
    expires_at: string | null;
    is_multiple: boolean;
    max_choice: number;
    options: number[] | PollChoice[];
    result_counters: VoteResultCounters | FeedbackResultCounters;
    candidate_users?: User[];
    created_by: number;
    creator?: User;
    workplace?: WorkplaceSimple;
    responses_count?: number;
    created_at: string;
    updated_at: string;
}
