import { WorkplaceSimple } from '@/types/models/workplace';

export interface Announcement {
    id: number;
    title: string;
    content: string;
    image_path: string | null;
    image_url: string | null;
    start_at: string;
    end_at: string;
    is_active: boolean;
    state: string;
    created_by: number | null;
    created_at: string;
    updated_at: string;
}

export interface AnnouncementTranslation {
    id: number;
    announcement_id: number;
    lang: string;
    title: string;
    content: string;
}

export interface AnnouncementWithRelation extends Announcement {
    workplaces: WorkplaceSimple[];
    translations: AnnouncementTranslation[];
}
