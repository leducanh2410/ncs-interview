import { PermissionNameEnum } from '@/enums/permission_name.enum';
import { RoleNameEnum } from '@/enums/role_name.enum';

export type RouteInfo = {
    path: string;
    name: string;
    permissions?: string[];
    roles?: string[];
    isPublic?: boolean;
};

export type RouteName =
    | 'AUTH_LOGIN'
    | 'AUTH_TFA'
    | 'AUTH_PASSWORD_FORGOT'
    | 'AUTH_PASSWORD_RESET'
    | 'HOME'
    | 'MY_PAGE_PROFILE'
    | 'MY_PAGE_SETTINGS'
    | 'ADMIN_ROLE_LIST'
    | 'ADMIN_ROLE_CREATE'
    | 'ADMIN_ROLE_DETAIL'
    | 'ADMIN_GROUP_LIST'
    | 'ADMIN_GROUP_CREATE'
    | 'ADMIN_GROUP_DETAIL'
    | 'ADMIN_USER_LIST'
    | 'ADMIN_USER_CREATE'
    | 'ADMIN_USER_DETAIL'
    | 'ADMIN_USER_FACE_LIST'
    | 'ADMIN_USER_PAPER_RECONCILIATION'
    | 'ADMIN_CHECK_IN_SPOOF_LIST'
    | 'ADMIN_ATTENDANCE_LIST'
    | 'ADMIN_ATTENDANCE_DAY_DETAIL'
    | 'ADMIN_WORKPLACE_LIST'
    | 'ADMIN_WORKPLACE_DETAIL'
    | 'ADMIN_FIXED_SCHEDULE_LIST'
    | 'ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG'
    | 'ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST'
    | 'ADMIN_ADMIN_LEAVE_LIST'
    | 'ADMIN_ADMIN_LEAVE_HOLIDAYS'
    | 'ADMIN_PDF_TEMPLATE_LIST'
    | 'ADMIN_PDF_TEMPLATE_DETAIL'
    | 'ADMIN_PDF_EXPORT_EXCEL'
    | 'ADMIN_BANK_ACCOUNT_LIST'
    | 'EXPORT_HISTORY'
    | 'IMPORT_HISTORY'
    | 'IMPORT_BATCH_DETAIL'
    | 'MANAGE_SHIFT_ORDER_IMPORT'
    | 'EXPORT_BATCH_DETAIL'
    | 'ADMIN_FORECAST'
    | 'MANAGE_POLL_LIST'
    | 'MANAGE_POLL_RESULTS'
    | 'ADMIN_CHAT_SUPPORT_LIST'
    | 'ADMIN_CHAT_SUPPORT_DETAIL'
    | 'ADMIN_CONFIG'
    | 'ADMIN_CHAT_BANNED_KEYWORDS'
    | 'ADMIN_CHAT_SUPPORT_TYPE_CONFIG'
    | 'MANAGE_SHIFT_DETAIL'
    | 'MANAGE_SHIFT_LIST'
    | 'MANAGE_SHIFT_PLANNING'
    | 'MANAGE_SHIFT_WEEKLY_WORK_HOURS'
    | 'MANAGE_ABSENT_NOTICE'
    | 'MANAGE_WORK_HISTORY'
    | 'MANAGE_WORK_HISTORY_MONTHLY'
    | 'MANAGE_WORK_HISTORY_EDIT_REQUESTS'
    | 'MANAGE_WORK_HISTORY_RECONCILIATION'
    | 'MANAGE_WORK_HISTORY_RECONCILIATION_DETAIL'
    | 'MANAGE_SALARY'
    | 'MANAGE_SALARY_PROCESS'
    | 'MANAGE_SALARY_REQUEST'
    | 'MANAGE_SALARY_REQUEST_DETAIL'
    | 'MANAGE_REPORT_LIST'
    | 'MANAGE_MONTHLY_REPORT_LIST'
    | 'MANAGE_NOTIFICATION'
    | 'MANAGE_ANNOUNCEMENT'
    | 'MANAGE_DOCUMENT'
    | 'MANAGE_EXAM'
    | 'MANAGE_EXAM_DETAIL'
    | 'MANAGE_EXAM_CREATE'
    | 'MANAGE_EXAM_UPDATE'
    | 'MANAGE_EXAM_RESULT'
    | 'MANAGE_EXAM_RESULT_DETAIL'
    | 'MANAGE_DOCUMENT_DETAIL'
    | 'MANAGE_DOCUMENT_READ_CONFIG'
    | 'MANAGE_DOCUMENT_READ_CONFIG_DETAIL'
    | 'MANAGE_DOCUMENT_READ_CONFIRMATION'
    | 'MANAGE_USER_DETAIL_SCHEDULE'
    | 'PRIVATE_POLICY_JA'
    | 'PRIVATE_POLICY_EN'
    | 'PRIVACY_POLICY_VI'
    | 'TERMS_OF_SERVICE_JA'
    | 'TERMS_OF_SERVICE_EN'
    | 'TERMS_OF_SERVICE_VI'
    | 'ADMIN_PRIVACY_POLICY_EN'
    | 'ADMIN_PRIVACY_POLICY_VI'
    | 'ADMIN_PRIVACY_POLICY_JA'
    | 'ADMIN_TERMS_OF_SERVICE_EN'
    | 'ADMIN_TERMS_OF_SERVICE_VI'
    | 'ADMIN_TERMS_OF_SERVICE_JA';

const RouteConfig: { [name in RouteName]: RouteInfo } = {
    AUTH_LOGIN: {
        path: '/auth/login',
        name: 'Login',
        isPublic: true,
    },
    AUTH_TFA: {
        path: '/auth/login/tfa',
        name: 'Login TFA',
        isPublic: true,
    },
    AUTH_PASSWORD_FORGOT: {
        path: '/auth/password/forgot',
        name: 'Forgot Password',
        isPublic: true,
    },
    AUTH_PASSWORD_RESET: {
        path: '/auth/password/reset',
        name: 'Reset Password',
        isPublic: true,
    },
    PRIVACY_POLICY_VI: {
        path: '/privacy-policy/vi',
        name: 'Privacy Policy',
        isPublic: true,
    },
    PRIVATE_POLICY_JA: {
        path: '/privacy-policy/ja',
        name: 'Privacy Policy',
        isPublic: true,
    },
    PRIVATE_POLICY_EN: {
        path: '/privacy-policy/en',
        name: 'Privacy Policy',
        isPublic: true,
    },
    TERMS_OF_SERVICE_VI: {
        path: '/terms-of-service/vi',
        name: 'Terms of Service',
        isPublic: true,
    },
    TERMS_OF_SERVICE_JA: {
        path: '/terms-of-service/ja',
        name: 'Terms of Service',
        isPublic: true,
    },
    TERMS_OF_SERVICE_EN: {
        path: '/terms-of-service/en',
        name: 'Terms of Service',
        isPublic: true,
    },
    ADMIN_PRIVACY_POLICY_EN: {
        path: '/admin-privacy-policy/en',
        name: 'Admin Privacy Policy',
        isPublic: true,
    },
    ADMIN_PRIVACY_POLICY_VI: {
        path: '/admin-privacy-policy/vi',
        name: 'Admin Privacy Policy',
        isPublic: true,
    },
    ADMIN_PRIVACY_POLICY_JA: {
        path: '/admin-privacy-policy/ja',
        name: 'Admin Privacy Policy',
        isPublic: true,
    },
    ADMIN_TERMS_OF_SERVICE_EN: {
        path: '/admin-terms-of-service/en',
        name: 'Admin Terms of Service',
        isPublic: true,
    },
    ADMIN_TERMS_OF_SERVICE_VI: {
        path: '/admin-terms-of-service/vi',
        name: 'Admin Terms of Service',
        isPublic: true,
    },
    ADMIN_TERMS_OF_SERVICE_JA: {
        path: '/admin-terms-of-service/ja',
        name: 'Admin Terms of Service',
        isPublic: true,
    },

    HOME: {
        path: '/',
        name: 'Dashboard',
        permissions: [PermissionNameEnum.SHIFT_LIST.value],
    },
    MY_PAGE_PROFILE: {
        path: '/my-page/profile',
        name: 'Profile',
    },
    MY_PAGE_SETTINGS: {
        path: '/my-page/settings',
        name: 'Settings',
    },

    ADMIN_ROLE_LIST: {
        path: '/admin/role',
        name: 'Role List',
        permissions: [PermissionNameEnum.ROLE_LIST.value, PermissionNameEnum.ROLE_CREATE.value],
    },
    ADMIN_ROLE_CREATE: {
        path: '/admin/role/create',
        name: 'Role List',
        permissions: [PermissionNameEnum.ROLE_CREATE.value],
    },
    ADMIN_ROLE_DETAIL: {
        path: '/admin/role/[id]',
        name: 'Role Detail',
        permissions: [PermissionNameEnum.ROLE_LIST.value, PermissionNameEnum.ROLE_EDIT.value],
    },

    ADMIN_GROUP_LIST: {
        path: '/admin/group',
        name: 'Group List',
        permissions: [PermissionNameEnum.GROUP_VIEW.value],
    },
    ADMIN_GROUP_CREATE: {
        path: '/admin/group/create',
        name: 'Create Group',
        permissions: [PermissionNameEnum.GROUP_EDIT.value],
    },
    ADMIN_GROUP_DETAIL: {
        path: '/admin/group/[id]',
        name: 'Group Detail',
        permissions: [PermissionNameEnum.GROUP_VIEW.value],
    },

    ADMIN_USER_LIST: {
        path: '/admin/user',
        name: 'User List',
        permissions: [PermissionNameEnum.USER_LIST.value, PermissionNameEnum.USER_CREATE.value],
    },
    ADMIN_USER_CREATE: {
        path: '/admin/user/create',
        name: 'Create Employee',
        permissions: [PermissionNameEnum.USER_CREATE_EMPLOYEE.value],
    },
    ADMIN_USER_DETAIL: {
        path: '/admin/user/[id]',
        name: 'User Detail',
        permissions: [PermissionNameEnum.USER_LIST.value],
    },
    ADMIN_USER_FACE_LIST: {
        path: '/admin/user-face',
        name: 'User Face List',
        permissions: [PermissionNameEnum.USER_FACE_LIST.value],
    },
    // A sibling path rather than /admin/user/paper-reconciliation, which would sit under
    // the [id] dynamic segment and read as an employee id.
    ADMIN_USER_PAPER_RECONCILIATION: {
        path: '/admin/user-paper-reconciliation',
        name: 'Document reconciliation',
        permissions: [PermissionNameEnum.USER_PAPER_LIST.value],
    },
    ADMIN_CHECK_IN_SPOOF_LIST: {
        path: '/admin/check-in-spoof',
        name: 'Spoofed Check-Ins',
        permissions: [PermissionNameEnum.CHECK_IN_SPOOF_LIST.value],
    },
    ADMIN_ATTENDANCE_LIST: {
        path: '/admin/attendance',
        name: 'Admin attendance',
        // Web attendance history is restricted to GlobalAdmin (who manages admins).
        roles: [RoleNameEnum.GLOBAL_ADMIN.value],
    },
    ADMIN_ATTENDANCE_DAY_DETAIL: {
        path: '/admin/attendance/day',
        name: 'Admin attendance detail',
        roles: [RoleNameEnum.GLOBAL_ADMIN.value],
    },
    ADMIN_WORKPLACE_LIST: {
        path: '/admin/workplace',
        name: 'Workplace List',
        permissions: [PermissionNameEnum.WORKPLACE_LIST.value, PermissionNameEnum.WORKPLACE_CREATE.value],
    },
    ADMIN_WORKPLACE_DETAIL: {
        path: '/admin/workplace/[id]',
        name: 'Workplace Detail',
        permissions: [PermissionNameEnum.WORKPLACE_LIST.value],
    },
    ADMIN_FIXED_SCHEDULE_LIST: {
        path: '/admin/fixed-schedule',
        name: 'Fixed Schedule List',
        permissions: [PermissionNameEnum.FIXED_SCHEDULE_LIST.value, PermissionNameEnum.FIXED_SCHEDULE_CREATE.value],
    },
    ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG: {
        path: '/admin/fixed-schedule/change-config',
        name: 'Fixed Schedule Change Config',
        permissions: [PermissionNameEnum.FIXED_SCHEDULE_CHANGE_CONFIG_LIST.value],
    },
    ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST: {
        path: '/admin/fixed-schedule/change-requests',
        name: 'Fixed Schedule Change Request',
        permissions: [PermissionNameEnum.FIXED_SCHEDULE_CHANGE_REQUEST_LIST.value],
    },
    ADMIN_ADMIN_LEAVE_LIST: {
        path: '/admin/admin-leave',
        name: 'Admin Leave Requests',
        permissions: [PermissionNameEnum.ADMIN_LEAVE_MANAGE.value],
    },
    ADMIN_ADMIN_LEAVE_HOLIDAYS: {
        path: '/admin/admin-leave/holidays',
        name: 'Company Holidays',
        permissions: [PermissionNameEnum.ADMIN_LEAVE_MANAGE.value],
    },
    ADMIN_PDF_TEMPLATE_LIST: {
        path: '/admin/pdf-template',
        name: 'PDF Template List',
        permissions: [PermissionNameEnum.PDF_TEMPLATE_LIST.value, PermissionNameEnum.PDF_TEMPLATE_CREATE.value],
    },
    ADMIN_PDF_TEMPLATE_DETAIL: {
        path: '/admin/pdf-template/[id]',
        name: 'PDF Template Detail',
        permissions: [PermissionNameEnum.PDF_TEMPLATE_LIST.value, PermissionNameEnum.PDF_TEMPLATE_UPDATE.value],
    },
    ADMIN_PDF_EXPORT_EXCEL: {
        path: '/admin/pdf-export-excel',
        name: 'Export PDF from Excel',
        permissions: [PermissionNameEnum.PDF_TEMPLATE_EXPORT.value],
    },
    ADMIN_BANK_ACCOUNT_LIST: {
        path: '/admin/bank-account',
        name: 'Bank accounts',
        permissions: [PermissionNameEnum.BANK_ACCOUNT_LIST.value],
    },
    EXPORT_HISTORY: {
        path: '/export-history',
        name: 'Export History',
        permissions: [
            PermissionNameEnum.PDF_TEMPLATE_LIST.value,
            PermissionNameEnum.USER_EXPORT_CSV.value,
            PermissionNameEnum.WORK_HISTORY_EXPORT.value,
        ],
    },
    EXPORT_BATCH_DETAIL: {
        path: '/export-history/[id]',
        name: 'Export Batch Detail',
        permissions: [
            PermissionNameEnum.PDF_TEMPLATE_LIST.value,
            PermissionNameEnum.USER_EXPORT_CSV.value,
            PermissionNameEnum.WORK_HISTORY_EXPORT.value,
        ],
    },
    IMPORT_HISTORY: {
        path: '/import-history',
        name: 'Import History',
        permissions: [PermissionNameEnum.SHIFT_IMPORT_ORDER.value],
    },
    IMPORT_BATCH_DETAIL: {
        path: '/import-history/[id]',
        name: 'Import Batch Detail',
        permissions: [PermissionNameEnum.SHIFT_IMPORT_ORDER.value],
    },
    MANAGE_SHIFT_ORDER_IMPORT: {
        path: '/manage/shift/import-order/[id]',
        name: 'Shift Order Import',
        permissions: [PermissionNameEnum.SHIFT_IMPORT_ORDER.value],
    },
    ADMIN_FORECAST: {
        path: '/admin/forecast',
        name: 'Forecast',
        permissions: [PermissionNameEnum.SHIFT_LIST.value],
    },
    MANAGE_POLL_LIST: {
        path: '/manage/poll',
        name: 'Poll List',
        permissions: [PermissionNameEnum.POLL_LIST.value, PermissionNameEnum.POLL_CREATE.value],
    },
    MANAGE_POLL_RESULTS: {
        path: '/manage/poll/[id]/results',
        name: 'Poll Results',
        permissions: [PermissionNameEnum.POLL_LIST.value],
    },
    ADMIN_CHAT_SUPPORT_LIST: {
        path: '/admin/chat/support',
        name: 'Support Chats',
        permissions: [PermissionNameEnum.CHAT_ASSIGN.value],
    },
    ADMIN_CHAT_SUPPORT_DETAIL: {
        path: '/admin/chat/support/[id]',
        name: 'Support Chat Detail',
        permissions: [PermissionNameEnum.CHAT_ASSIGN.value],
    },
    ADMIN_CONFIG: {
        path: '/admin/configuration',
        name: 'Configuration',
        permissions: [PermissionNameEnum.CONFIG_LIST.value],
    },
    ADMIN_CHAT_BANNED_KEYWORDS: {
        path: '/admin/chat/banned-keywords',
        name: 'Banned Chat Keywords',
        permissions: [PermissionNameEnum.CONFIG_LIST.value],
    },
    ADMIN_CHAT_SUPPORT_TYPE_CONFIG: {
        path: '/admin/chat/support-type-config',
        name: 'Support Type Admin Assignment',
        permissions: [PermissionNameEnum.CHAT_ASSIGN.value],
    },

    MANAGE_SHIFT_LIST: {
        path: '/manage/shift',
        name: 'Shift List',
        permissions: [PermissionNameEnum.SHIFT_LIST.value, PermissionNameEnum.SHIFT_CREATE.value],
    },

    MANAGE_SHIFT_DETAIL: {
        path: '/manage/shift/[id]',
        name: 'Shift Detail',
        permissions: [PermissionNameEnum.SHIFT_LIST.value, PermissionNameEnum.SHIFT_EDIT.value],
    },

    MANAGE_SHIFT_WEEKLY_WORK_HOURS: {
        path: '/manage/shift/weekly-hours',
        name: 'Weekly work hours',
        permissions: [PermissionNameEnum.SHIFT_LIST.value],
    },

    MANAGE_SHIFT_PLANNING: {
        path: '/manage/shift-planning',
        name: 'Shift Planner',
        permissions: [
            PermissionNameEnum.SHIFT_LIST.value,
            PermissionNameEnum.SHIFT_CREATE.value,
            PermissionNameEnum.SHIFT_EDIT.value,
        ],
    },

    MANAGE_ABSENT_NOTICE: {
        path: '/manage/absent-notice',
        name: 'Absent Notices',
        permissions: [PermissionNameEnum.SHIFT_LIST.value],
    },

    MANAGE_WORK_HISTORY: {
        path: '/manage/work-history',
        name: 'Work history',
        permissions: [
            PermissionNameEnum.WORK_HISTORY_LIST.value,
            PermissionNameEnum.WORK_HISTORY_ADD.value,
            PermissionNameEnum.WORK_HISTORY_EXPORT.value,
        ],
    },
    MANAGE_WORK_HISTORY_MONTHLY: {
        path: '/manage/work-history/monthly',
        name: 'Monthly report',
        permissions: [PermissionNameEnum.WORK_HISTORY_LIST.value, PermissionNameEnum.WORK_HISTORY_EXPORT.value],
    },
    MANAGE_WORK_HISTORY_EDIT_REQUESTS: {
        path: '/manage/work-history/edit-requests',
        name: 'Work History Edit Requests',
        permissions: [PermissionNameEnum.WORK_HISTORY_EDIT_REQUEST_PROCESS.value],
    },
    MANAGE_WORK_HISTORY_RECONCILIATION: {
        path: '/manage/work-history/reconciliation',
        name: 'Attendance reconciliation',
        permissions: [PermissionNameEnum.RECONCILIATION_WORK.value],
    },
    MANAGE_WORK_HISTORY_RECONCILIATION_DETAIL: {
        path: '/manage/work-history/reconciliation/[id]',
        name: 'Attendance reconciliation',
        permissions: [PermissionNameEnum.RECONCILIATION_WORK.value],
    },

    MANAGE_SALARY: {
        path: '/manage/salary',
        name: 'Salary',
        permissions: [PermissionNameEnum.SALARY_LIST.value, PermissionNameEnum.SALARY_EXPORT.value],
    },
    MANAGE_SALARY_PROCESS: {
        path: '/manage/salary/process',
        name: 'Salary process',
        permissions: [PermissionNameEnum.SALARY_PROCESS_LIST.value, PermissionNameEnum.SALARY_PROCESS_CREATE.value],
    },
    MANAGE_SALARY_REQUEST: {
        path: '/manage/salary-request',
        name: 'Salary request',
        permissions: [
            PermissionNameEnum.SALARY_REQUEST_LIST.value,
            PermissionNameEnum.SALARY_REQUEST_CREATE.value,
            PermissionNameEnum.SALARY_REQUEST_EXPORT.value,
        ],
    },
    MANAGE_SALARY_REQUEST_DETAIL: {
        path: '/manage/salary-request/[id]',
        name: 'Salary request detail',
        permissions: [PermissionNameEnum.SALARY_REQUEST_LIST.value],
    },

    MANAGE_REPORT_LIST: {
        path: '/manage/report',
        name: 'Report',
        permissions: [PermissionNameEnum.WORK_REPORT_LIST.value, PermissionNameEnum.WORK_REPORT_EXPORT.value],
    },
    MANAGE_MONTHLY_REPORT_LIST: {
        path: '/manage/report/monthly',
        name: 'Monthly report',
        permissions: [PermissionNameEnum.WORK_REPORT_LIST.value, PermissionNameEnum.WORK_REPORT_EXPORT.value],
    },
    MANAGE_NOTIFICATION: {
        path: '/manage/notification',
        name: 'Notification',
        permissions: [PermissionNameEnum.NOTIFICATION_LIST.value, PermissionNameEnum.NOTIFICATION_CREATE.value],
    },
    MANAGE_ANNOUNCEMENT: {
        path: '/manage/announcement',
        name: 'Announcement',
        permissions: [PermissionNameEnum.ANNOUNCEMENT_LIST.value, PermissionNameEnum.ANNOUNCEMENT_CREATE.value],
    },
    MANAGE_EXAM: {
        path: '/manage/exam',
        name: 'Exam',
        permissions: [PermissionNameEnum.EXAM_LIST.value, PermissionNameEnum.EXAM_CREATE.value],
    },
    MANAGE_EXAM_DETAIL: {
        path: '/manage/exam/[id]',
        name: 'Exam Detail',
        permissions: [PermissionNameEnum.EXAM_LIST.value],
    },
    MANAGE_EXAM_CREATE: {
        path: '/manage/exam/create',
        name: 'Create Exam',
        permissions: [PermissionNameEnum.EXAM_CREATE.value],
    },
    MANAGE_EXAM_UPDATE: {
        path: '/manage/exam/[id]/update',
        name: 'Update Exam',
        permissions: [PermissionNameEnum.EXAM_EDIT.value],
    },
    MANAGE_EXAM_RESULT: {
        path: '/manage/exam-result',
        name: 'Exam result',
        permissions: [PermissionNameEnum.EXAM_RESULT_LIST.value],
    },
    MANAGE_EXAM_RESULT_DETAIL: {
        path: '/manage/exam-result/[id]',
        name: 'Exam result Detail',
        permissions: [PermissionNameEnum.EXAM_RESULT_LIST.value],
    },
    MANAGE_DOCUMENT: {
        path: '/manage/document',
        name: 'Document',
        permissions: [PermissionNameEnum.DOCUMENT_LIST.value, PermissionNameEnum.DOCUMENT_CREATE.value],
    },
    MANAGE_DOCUMENT_DETAIL: {
        path: '/manage/document/[id]',
        name: 'Document Detail',
        permissions: [PermissionNameEnum.DOCUMENT_LIST.value],
    },
    MANAGE_DOCUMENT_READ_CONFIG: {
        path: '/manage/document-read-config',
        name: 'Document Read Config',
        permissions: [PermissionNameEnum.CONFIG_LIST.value, PermissionNameEnum.CONFIG_EDIT.value],
    },
    MANAGE_DOCUMENT_READ_CONFIG_DETAIL: {
        path: '/manage/document-read-config/[id]',
        name: 'Document Read Config Detail',
        permissions: [PermissionNameEnum.CONFIG_LIST.value],
    },
    MANAGE_DOCUMENT_READ_CONFIRMATION: {
        path: '/manage/document-read-confirmation',
        name: 'Document Read Confirmation',
        permissions: [PermissionNameEnum.CONFIG_LIST.value],
    },
    MANAGE_USER_DETAIL_SCHEDULE: {
        path: '/manage/user/[id]/schedule',
        name: 'User Detail Schedule',
        permissions: [PermissionNameEnum.USER_LIST.value],
    },
};

export default RouteConfig;
