import {
    LuAward,
    LuBuilding,
    LuCircleDollarSign,
    LuFileCheck,
    LuFileWarning,
    LuHeart,
    LuInfo,
    LuMegaphone,
    LuSettings,
    LuSparkle,
} from 'react-icons/lu';

export type NotificationIcon =
    | 'ANNOUNCEMENT'
    | 'COG'
    | 'HEART'
    | 'GEMINI'
    | 'FILE'
    | 'BUILDING'
    | 'AWARD'
    | 'MONEY'
    | 'INFO'
    | 'WARNING';

export const NotificationIconEnum: { [key in NotificationIcon]: { value: string; text: string } } = Object.freeze({
    ANNOUNCEMENT: { value: 'announcement', text: 'Announcement' },
    COG: { value: 'cog', text: 'Cog' },
    HEART: { value: 'heart', text: 'Heart' },
    GEMINI: { value: 'gemini', text: 'Gemini' },
    FILE: { value: 'file', text: 'File' },
    BUILDING: { value: 'building', text: 'Building' },
    AWARD: { value: 'award', text: 'Award' },
    MONEY: { value: 'money', text: 'Money' },
    INFO: { value: 'info', text: 'Info' },
    WARNING: { value: 'warning', text: 'Warning' },
});

export const getNotificationIconComponent = (icon: string) => {
    switch (icon) {
        case NotificationIconEnum.ANNOUNCEMENT.value:
            return LuMegaphone;
        case NotificationIconEnum.COG.value:
            return LuSettings;
        case NotificationIconEnum.HEART.value:
            return LuHeart;
        case NotificationIconEnum.GEMINI.value:
            return LuSparkle;
        case NotificationIconEnum.FILE.value:
            return LuFileCheck;
        case NotificationIconEnum.BUILDING.value:
            return LuBuilding;
        case NotificationIconEnum.AWARD.value:
            return LuAward;
        case NotificationIconEnum.MONEY.value:
            return LuCircleDollarSign;
        case NotificationIconEnum.INFO.value:
            return LuInfo;
        case NotificationIconEnum.WARNING.value:
            return LuFileWarning;
        default:
            return LuMegaphone;
    }
};
