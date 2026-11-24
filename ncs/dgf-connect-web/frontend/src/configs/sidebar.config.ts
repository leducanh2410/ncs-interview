import {
    LuBell,
    LuBookOpen,
    LuCalendarCheck,
    LuCalendarCog,
    LuCalendarRange,
    LuCircleDollarSign,
    LuClipboardList,
    LuClock,
    LuDollarSign,
    LuFactory,
    LuFileCheck,
    LuFileSearch,
    LuFileSpreadsheet,
    LuFileText,
    LuHistory,
    LuLandmark,
    LuLayoutDashboard,
    LuLayoutList,
    LuLifeBuoy,
    LuMapPin,
    LuMegaphone,
    LuScrollText,
    LuSettings,
    LuShield,
    LuShieldAlert,
    LuSmile,
    LuSquareCheck,
    LuTags,
    LuTrendingUp,
    LuUsers,
} from 'react-icons/lu';
import RouteConfig, { RouteInfo } from '@/configs/route.config';

export type SidebarConfigItem = {
    route: RouteInfo;
    customName?: string;
    icon: any;
    badge?: string;
};

export type SidebarGap = {
    key: string;
    gap: number;
};

export type SidebarGroup = {
    group: string;
    icon: any;
    items: SidebarConfigItem[];
};

const SidebarConfig: Array<SidebarConfigItem | SidebarGap | SidebarGroup> = [
    {
        route: RouteConfig.HOME,
        icon: LuLayoutDashboard,
    },
    {
        route: RouteConfig.ADMIN_ROLE_LIST,
        customName: 'Role management',
        icon: LuShield,
    },
    {
        group: 'User',
        icon: LuUsers,
        items: [
            { route: RouteConfig.ADMIN_USER_LIST, customName: 'User list', icon: LuUsers },
            { route: RouteConfig.ADMIN_GROUP_LIST, customName: 'Groups', icon: LuTags },
            {
                route: RouteConfig.ADMIN_USER_PAPER_RECONCILIATION,
                customName: 'Document reconciliation',
                icon: LuFileSearch,
            },
            { route: RouteConfig.ADMIN_USER_FACE_LIST, customName: 'Face registration', icon: LuSmile },
            { route: RouteConfig.ADMIN_BANK_ACCOUNT_LIST, customName: 'Bank accounts', icon: LuLandmark },
        ],
    },
    {
        group: 'Workplace',
        icon: LuFactory,
        items: [{ route: RouteConfig.ADMIN_WORKPLACE_LIST, customName: 'Workplace list', icon: LuLayoutList }],
    },
    {
        group: 'Fixed schedule',
        icon: LuCalendarCheck,
        items: [
            { route: RouteConfig.ADMIN_FIXED_SCHEDULE_LIST, customName: 'Fixed schedule list', icon: LuLayoutList },
            {
                route: RouteConfig.ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG,
                customName: 'Change config',
                icon: LuSettings,
            },
            {
                route: RouteConfig.ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST,
                customName: 'Change requests',
                icon: LuClipboardList,
            },
        ],
    },
    {
        group: 'PDF Template',
        icon: LuFileText,
        items: [
            { route: RouteConfig.ADMIN_PDF_TEMPLATE_LIST, customName: 'Template list', icon: LuLayoutList },
            { route: RouteConfig.ADMIN_PDF_EXPORT_EXCEL, customName: 'Export from Excel', icon: LuFileSpreadsheet },
        ],
    },
    {
        group: 'Setting',
        icon: LuSettings,
        items: [{ route: RouteConfig.ADMIN_CONFIG, customName: 'Configuration', icon: LuSettings }],
    },
    {
        group: 'Admin Management',
        icon: LuClipboardList,
        items: [
            { route: RouteConfig.ADMIN_ADMIN_LEAVE_LIST, customName: 'Leave requests', icon: LuClipboardList },
            { route: RouteConfig.ADMIN_ADMIN_LEAVE_HOLIDAYS, customName: 'Company holidays', icon: LuCalendarRange },
        ],
    },
    { key: 'admin-manage-gap', gap: 16 },
    {
        group: 'Shift',
        icon: LuCalendarCog,
        items: [
            { route: RouteConfig.ADMIN_FORECAST, customName: 'Forecast', icon: LuTrendingUp },
            { route: RouteConfig.MANAGE_SHIFT_LIST, customName: 'Shift list', icon: LuLayoutList },
            { route: RouteConfig.MANAGE_SHIFT_WEEKLY_WORK_HOURS, customName: 'Weekly work hours', icon: LuClock },
            { route: RouteConfig.MANAGE_SHIFT_PLANNING, customName: 'Shift Planner', icon: LuCalendarRange },
            { route: RouteConfig.MANAGE_ABSENT_NOTICE, customName: 'Absent notice', icon: LuClipboardList },
        ],
    },
    {
        group: 'Check in',
        icon: LuCalendarCheck,
        items: [
            { route: RouteConfig.MANAGE_WORK_HISTORY, customName: 'History', icon: LuHistory },
            {
                route: RouteConfig.MANAGE_WORK_HISTORY_EDIT_REQUESTS,
                customName: 'Work history edit requests',
                icon: LuClipboardList,
            },
            {
                route: RouteConfig.MANAGE_WORK_HISTORY_RECONCILIATION,
                customName: 'Attendance reconciliation',
                icon: LuClipboardList,
            },
            { route: RouteConfig.ADMIN_ATTENDANCE_LIST, customName: 'Admin attendance', icon: LuMapPin },
            { route: RouteConfig.ADMIN_CHECK_IN_SPOOF_LIST, customName: 'Spoof check in', icon: LuShieldAlert },
            { route: RouteConfig.MANAGE_REPORT_LIST, customName: 'Report', icon: LuClipboardList },
        ],
    },
    {
        group: 'Salary',
        icon: LuDollarSign,
        items: [
            { route: RouteConfig.MANAGE_SALARY, customName: 'Salary list', icon: LuDollarSign },
            { route: RouteConfig.MANAGE_SALARY_REQUEST, customName: 'Salary request', icon: LuCircleDollarSign },
        ],
    },
    {
        route: RouteConfig.EXPORT_HISTORY,
        customName: 'Export history',
        icon: LuHistory,
    },
    {
        route: RouteConfig.IMPORT_HISTORY,
        customName: 'Import history',
        icon: LuHistory,
    },
    { key: 'salary-notification-gap', gap: 16 },
    {
        route: RouteConfig.MANAGE_NOTIFICATION,
        customName: 'Notification',
        icon: LuBell,
    },
    {
        route: RouteConfig.MANAGE_ANNOUNCEMENT,
        customName: 'Announcement',
        icon: LuMegaphone,
    },
    {
        group: 'Document',
        icon: LuFileText,
        items: [
            { route: RouteConfig.MANAGE_DOCUMENT, customName: 'Document list', icon: LuLayoutList },
            { route: RouteConfig.MANAGE_DOCUMENT_READ_CONFIG, customName: 'Send to users', icon: LuFileCheck },
            {
                route: RouteConfig.MANAGE_DOCUMENT_READ_CONFIRMATION,
                customName: 'User confirmations',
                icon: LuClipboardList,
            },
        ],
    },
    {
        group: 'Exam',
        icon: LuBookOpen,
        items: [
            { route: RouteConfig.MANAGE_EXAM, customName: 'List exam', icon: LuBookOpen },
            { route: RouteConfig.MANAGE_EXAM_RESULT, customName: 'List result', icon: LuScrollText },
        ],
    },
    {
        route: RouteConfig.MANAGE_POLL_LIST,
        customName: 'Vote/Feedback',
        icon: LuSquareCheck,
    },
    {
        group: 'Support chats',
        icon: LuLifeBuoy,
        items: [
            { route: RouteConfig.ADMIN_CHAT_SUPPORT_LIST, customName: 'Support chats', icon: LuLifeBuoy },
            {
                route: RouteConfig.ADMIN_CHAT_SUPPORT_TYPE_CONFIG,
                customName: 'Support type admin assignment',
                icon: LuUsers,
            },
            { route: RouteConfig.ADMIN_CHAT_BANNED_KEYWORDS, customName: 'Banned chat keywords', icon: LuShieldAlert },
        ],
    },
];

export default SidebarConfig;
