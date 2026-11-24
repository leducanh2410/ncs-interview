import { Shift } from '@/types/models/shift';

export interface RecruitmentCriteria {
    shift_id: number;
    workplace_id: number;
    gender_target: string;
    requires_experience: boolean;
    include_nearby_workplaces: boolean;
    nearby_radius_km: number | null;
    include_frequent_urgent_group: boolean;
    hot_bonus_amount: number | null;
}

export interface Notification {
    id: number;
    type: string;
    title: string;
    content: string;
    status: string;
    icon: string | null;
    url: string | null;
    is_manually_scheduled: number;
    scheduled_at: string;
    processed_at: string | null;
    /** Recruitment targeting criteria; absent or partial on notifications predating targeting. */
    data: Partial<RecruitmentCriteria> | null;
    /** null until the recipient list has been resolved at send time; 0 means it reached nobody. */
    recipients_resolved_count: number | null;
    target_type: string;
    workplaces?: NotificationWorkplace[];
}

export interface NotificationWorkplace {
    id: number;
    name: string;
}
export interface NotificationWithRelation extends Notification {
    shift: Shift | null;
    translations: NotificationTranslation[];
    /** Recipients who have read it; only counted against the resolved recipient list. */
    read_count?: number;
}
