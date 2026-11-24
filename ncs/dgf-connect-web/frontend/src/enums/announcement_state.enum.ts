export type AnnouncementState = 'INACTIVE' | 'SCHEDULED' | 'ACTIVE' | 'EXPIRED';

export const AnnouncementStateEnum: { [key in AnnouncementState]: { value: string; text: string } } = Object.freeze({
    INACTIVE: { value: 'inactive', text: 'Inactive' },
    SCHEDULED: { value: 'scheduled', text: 'Scheduled' },
    ACTIVE: { value: 'active', text: 'Active' },
    EXPIRED: { value: 'expired', text: 'Expired' },
});

export const getAnnouncementStateColor = (state: string) => {
    switch (state) {
        case AnnouncementStateEnum.ACTIVE.value:
            return 'green';
        case AnnouncementStateEnum.SCHEDULED.value:
            return 'blue';
        case AnnouncementStateEnum.EXPIRED.value:
            return 'red';
        default:
            return 'gray';
    }
};
