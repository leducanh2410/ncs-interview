export interface NotificationTranslation {
    id: number;
    notification_id: number;
    lang: string;
    title: string;
    content: string;
    /** True when this row is filler content copied from another locale, not an admin-provided translation. */
    is_fallback: boolean;
}
