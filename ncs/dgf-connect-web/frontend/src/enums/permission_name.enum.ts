export type PermissionName =
    | 'MANAGER_AUTH'
    | 'MANAGER_PROFILE'
    | 'MANAGER_ATTENDANCE_CHECK_IN'
    | 'ROLE_LIST'
    | 'ROLE_CREATE'
    | 'ROLE_EDIT'
    | 'ROLE_DELETE'
    | 'USER_LIST'
    | 'USER_CREATE'
    | 'USER_CREATE_EMPLOYEE'
    | 'USER_EDIT'
    | 'USER_DISABLE'
    | 'USER_ENABLE'
    | 'USER_RESTORE'
    | 'USER_SYNC'
    | 'USER_ASSIGN_ROLE'
    | 'USER_RESET_PASSWORD'
    | 'USER_IMAGE_LIST'
    | 'USER_IMAGE_UPLOAD'
    | 'USER_IMAGE_DELETE'
    | 'USER_DEVICE_LIST'
    | 'USER_DEVICE_DELETE'
    | 'USER_WORKPLACE_LIST'
    | 'USER_WORKPLACE_ADD'
    | 'USER_WORKPLACE_REMOVE'
    | 'USER_PAPER_LIST'
    | 'USER_PAPER_UPLOAD'
    | 'USER_PAPER_APPROVE'
    | 'USER_PAPER_REJECT'
    | 'USER_PAPER_DELETE'
    | 'BANK_ACCOUNT_LIST'
    | 'BANK_ACCOUNT_APPROVE'
    | 'USER_CONFIG_LIST'
    | 'USER_CONFIG_EDIT'
    | 'USER_FACE_LIST'
    | 'USER_FACE_CREATE'
    | 'USER_FACE_UPDATE'
    | 'USER_FACE_DELETE'
    | 'CHECK_IN_SPOOF_LIST'
    | 'WORKPLACE_LIST'
    | 'WORKPLACE_CREATE'
    | 'WORKPLACE_EDIT'
    | 'WORKPLACE_DELETE'
    | 'WORKPLACE_IMAGE_LIST'
    | 'WORKPLACE_IMAGE_UPLOAD'
    | 'WORKPLACE_IMAGE_DELETE'
    | 'WORKPLACE_POSITION_LIST'
    | 'WORKPLACE_POSITION_CREATE'
    | 'WORKPLACE_POSITION_EDIT'
    | 'WORKPLACE_POSITION_DELETE'
    | 'WORKPLACE_SHIFT_LIST'
    | 'WORKPLACE_SHIFT_CREATE'
    | 'WORKPLACE_SHIFT_EDIT'
    | 'WORKPLACE_SHIFT_DELETE'
    | 'WORKPLACE_INSTRUCTION_LIST'
    | 'WORKPLACE_INSTRUCTION_CREATE'
    | 'WORKPLACE_INSTRUCTION_EDIT'
    | 'WORKPLACE_INSTRUCTION_DELETE'
    | 'WORKPLACE_USER_LIST'
    | 'WORKPLACE_USER_ADD'
    | 'WORKPLACE_USER_REMOVE'
    | 'FIXED_SCHEDULE_LIST'
    | 'FIXED_SCHEDULE_CREATE'
    | 'FIXED_SCHEDULE_EDIT'
    | 'FIXED_SCHEDULE_DELETE'
    | 'FIXED_SCHEDULE_CHANGE_CONFIG_LIST'
    | 'FIXED_SCHEDULE_CHANGE_CONFIG_EDIT'
    | 'FIXED_SCHEDULE_CHANGE_REQUEST_LIST'
    | 'FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS'
    | 'PDF_TEMPLATE_LIST'
    | 'PDF_TEMPLATE_CREATE'
    | 'PDF_TEMPLATE_UPDATE'
    | 'PDF_TEMPLATE_DELETE'
    | 'PDF_TEMPLATE_EXPORT'
    | 'USER_EXPORT_CSV'
    | 'CONFIG_LIST'
    | 'CONFIG_EDIT'
    | 'DOCUMENT_LIST'
    | 'DOCUMENT_CREATE'
    | 'DOCUMENT_EDIT'
    | 'DOCUMENT_DELETE'
    | 'SHIFT_LIST'
    | 'SHIFT_CREATE'
    | 'SHIFT_EDIT'
    | 'SHIFT_EXPORT'
    | 'SHIFT_IMPORT'
    | 'SHIFT_IMPORT_ORDER'
    | 'SHIFT_CHECK_IN'
    | 'SHIFT_UPDATE_SALARY'
    | 'SHIFT_UPDATE_OVERTIME'
    | 'SHIFT_DELETE'
    | 'WORK_HISTORY_LIST'
    | 'WORK_HISTORY_ADD'
    | 'WORK_HISTORY_EDIT'
    | 'WORK_HISTORY_EXPORT'
    | 'WORK_HISTORY_EDIT_REQUEST_PROCESS'
    | 'RECONCILIATION_WORK'
    | 'WORK_REPORT_LIST'
    | 'WORK_REPORT_EXPORT'
    | 'NOTIFICATION_LIST'
    | 'NOTIFICATION_CREATE'
    | 'NOTIFICATION_EDIT'
    | 'NOTIFICATION_DELETE'
    | 'ANNOUNCEMENT_LIST'
    | 'ANNOUNCEMENT_CREATE'
    | 'ANNOUNCEMENT_EDIT'
    | 'ANNOUNCEMENT_DELETE'
    | 'SALARY_LIST'
    | 'SALARY_COLLECT'
    | 'SALARY_EXPORT'
    | 'SALARY_IMPORT_DEDUCTION'
    | 'SALARY_IMPORT_TRANSFER'
    | 'SALARY_IMPORT_REMAINING'
    | 'SALARY_REQUEST_LIST'
    | 'SALARY_REQUEST_CREATE'
    | 'SALARY_REQUEST_EXPORT'
    | 'SALARY_REQUEST_APPROVE'
    | 'SALARY_REQUEST_APPROVE_ACCOUNTANT'
    | 'SALARY_REQUEST_REJECT'
    | 'SALARY_REQUEST_CONFIRM_PAID'
    | 'SALARY_PROCESS_LIST'
    | 'SALARY_PROCESS_CREATE'
    | 'SALARY_PROCESS_EDIT'
    | 'SALARY_PROCESS_DELETE'
    | 'EXAM_LIST'
    | 'EXAM_CREATE'
    | 'EXAM_EDIT'
    | 'EXAM_DELETE'
    | 'EXAM_RESULT_LIST'
    | 'EXAM_RESULT_EXPORT'
    | 'POLL_LIST'
    | 'POLL_CREATE'
    | 'POLL_EDIT'
    | 'POLL_DELETE'
    | 'CHAT_VIEW'
    | 'CHAT_CREATE'
    | 'CHAT_EDIT'
    | 'CHAT_ASSIGN'
    | 'CHAT_SUPPORT'
    | 'CHAT_DELETE_MESSAGE'
    | 'CHAT_DELETE_CONVERSATION'
    | 'GROUP_VIEW'
    | 'GROUP_EDIT'
    | 'ADMIN_LEAVE_MANAGE';

export const PermissionNameEnum: { [key in PermissionName]: { value: string; text: string } } = Object.freeze({
    MANAGER_AUTH: { value: 'manager.auth', text: 'Manager Auth' },
    MANAGER_PROFILE: { value: 'manager.profile', text: 'Manager Profile' },
    MANAGER_ATTENDANCE_CHECK_IN: { value: 'manager.attendance.check_in', text: 'Manager Attendance Check In' },
    ROLE_LIST: { value: 'role.list', text: 'Role List' },
    ROLE_CREATE: { value: 'role.create', text: 'Role Create' },
    ROLE_EDIT: { value: 'role.edit', text: 'Role Edit' },
    ROLE_DELETE: { value: 'role.delete', text: 'Role Delete' },
    USER_LIST: { value: 'user.general.list', text: 'User List' },
    USER_CREATE: { value: 'user.general.create', text: 'User Create' },
    USER_CREATE_EMPLOYEE: { value: 'user.general.create-employee', text: 'User Create Employee' },
    USER_EDIT: { value: 'user.general.edit', text: 'User Edit' },
    USER_DISABLE: { value: 'user.general.disable', text: 'User Disable' },
    USER_ENABLE: { value: 'user.general.enable', text: 'User Enable' },
    USER_RESTORE: { value: 'user.general.restore', text: 'User Restore' },
    USER_SYNC: { value: 'user.general.sync', text: 'User Sync' },
    USER_ASSIGN_ROLE: { value: 'user.general.assign_role', text: 'User Assign Role' },
    USER_RESET_PASSWORD: { value: 'user.general.reset-password', text: 'User Reset Password' },
    USER_IMAGE_LIST: { value: 'user.image.list', text: 'User Image List' },
    USER_IMAGE_UPLOAD: { value: 'user.image.upload', text: 'User Image Upload' },
    USER_IMAGE_DELETE: { value: 'user.image.delete', text: 'User Image Delete' },
    USER_DEVICE_LIST: { value: 'user.device.list', text: 'User Device List' },
    USER_DEVICE_DELETE: { value: 'user.device.delete', text: 'User Device Delete' },
    USER_WORKPLACE_LIST: { value: 'user.workplace.list', text: 'User Workplace List' },
    USER_WORKPLACE_ADD: { value: 'user.workplace.add', text: 'User Workplace Add' },
    USER_WORKPLACE_REMOVE: { value: 'user.workplace.remove', text: 'User Workplace Remove' },
    USER_PAPER_LIST: { value: 'user.paper.list', text: 'User Paper List' },
    USER_PAPER_UPLOAD: { value: 'user.paper.upload', text: 'User Paper Upload' },
    USER_PAPER_APPROVE: { value: 'user.paper.approve', text: 'User Paper Approve' },
    USER_PAPER_REJECT: { value: 'user.paper.reject', text: 'User Paper Reject' },
    USER_PAPER_DELETE: { value: 'user.paper.delete', text: 'User Paper Delete' },
    BANK_ACCOUNT_LIST: { value: 'bank_account.list', text: 'Bank Account List' },
    BANK_ACCOUNT_APPROVE: { value: 'bank_account.approve', text: 'Bank Account Approve' },
    USER_CONFIG_LIST: { value: 'user.config.list', text: 'User Config List' },
    USER_CONFIG_EDIT: { value: 'user.config.edit', text: 'User Config Edit' },
    USER_FACE_LIST: { value: 'user.face.list', text: 'User Face List' },
    USER_FACE_CREATE: { value: 'user.face.create', text: 'User Face Create' },
    USER_FACE_UPDATE: { value: 'user.face.update', text: 'User Face Update' },
    USER_FACE_DELETE: { value: 'user.face.delete', text: 'User Face Delete' },
    CHECK_IN_SPOOF_LIST: { value: 'check_in.spoof.list', text: 'Check In Spoof List' },
    WORKPLACE_LIST: { value: 'workplace.general.list', text: 'Workplace List' },
    WORKPLACE_CREATE: { value: 'workplace.general.create', text: 'Workplace Create' },
    WORKPLACE_EDIT: { value: 'workplace.general.edit', text: 'Workplace Edit' },
    WORKPLACE_DELETE: { value: 'workplace.general.delete', text: 'Workplace Delete' },
    WORKPLACE_IMAGE_LIST: { value: 'workplace.image.list', text: 'Workplace Image List' },
    WORKPLACE_IMAGE_UPLOAD: { value: 'workplace.image.upload', text: 'Workplace Image Upload' },
    WORKPLACE_IMAGE_DELETE: { value: 'workplace.image.delete', text: 'Workplace Image Delete' },
    WORKPLACE_POSITION_LIST: { value: 'workplace.position.list', text: 'Workplace Position List' },
    WORKPLACE_POSITION_CREATE: { value: 'workplace.position.create', text: 'Workplace Position Create' },
    WORKPLACE_POSITION_EDIT: { value: 'workplace.position.edit', text: 'Workplace Position Edit' },
    WORKPLACE_POSITION_DELETE: { value: 'workplace.position.delete', text: 'Workplace Position Delete' },
    WORKPLACE_SHIFT_LIST: { value: 'workplace.shift.list', text: 'Workplace Shift List' },
    WORKPLACE_SHIFT_CREATE: { value: 'workplace.shift.create', text: 'Workplace Shift Create' },
    WORKPLACE_SHIFT_EDIT: { value: 'workplace.shift.edit', text: 'Workplace Shift Edit' },
    WORKPLACE_SHIFT_DELETE: { value: 'workplace.shift.delete', text: 'Workplace Shift Delete' },
    WORKPLACE_INSTRUCTION_LIST: { value: 'workplace.instruction.list', text: 'Workplace Instruction List' },
    WORKPLACE_INSTRUCTION_CREATE: { value: 'workplace.instruction.create', text: 'Workplace Instruction Create' },
    WORKPLACE_INSTRUCTION_EDIT: { value: 'workplace.instruction.edit', text: 'Workplace Instruction Edit' },
    WORKPLACE_INSTRUCTION_DELETE: { value: 'workplace.instruction.delete', text: 'Workplace Instruction Delete' },
    WORKPLACE_USER_LIST: { value: 'workplace.user.list', text: 'Workplace User List' },
    WORKPLACE_USER_ADD: { value: 'workplace.user.add', text: 'Workplace User Add' },
    WORKPLACE_USER_REMOVE: { value: 'workplace.user.remove', text: 'Workplace User Remove' },
    FIXED_SCHEDULE_LIST: { value: 'fixed_schedule.list', text: 'Fixed Schedule List' },
    FIXED_SCHEDULE_CREATE: { value: 'fixed_schedule.create', text: 'Fixed Schedule Create' },
    FIXED_SCHEDULE_EDIT: { value: 'fixed_schedule.edit', text: 'Fixed Schedule Edit' },
    FIXED_SCHEDULE_DELETE: { value: 'fixed_schedule.delete', text: 'Fixed Schedule Delete' },
    FIXED_SCHEDULE_CHANGE_CONFIG_LIST: {
        value: 'fixed_schedule_change_config.list',
        text: 'Fixed Schedule Change Config List',
    },
    FIXED_SCHEDULE_CHANGE_CONFIG_EDIT: {
        value: 'fixed_schedule_change_config.edit',
        text: 'Fixed Schedule Change Config Edit',
    },
    FIXED_SCHEDULE_CHANGE_REQUEST_LIST: {
        value: 'fixed_schedule_change_request.list',
        text: 'Fixed Schedule Change Request List',
    },
    FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS: {
        value: 'fixed_schedule_change_request.process',
        text: 'Fixed Schedule Change Request Process',
    },
    PDF_TEMPLATE_LIST: { value: 'pdf_template.list', text: 'PDF Template List' },
    PDF_TEMPLATE_CREATE: { value: 'pdf_template.create', text: 'PDF Template Create' },
    PDF_TEMPLATE_UPDATE: { value: 'pdf_template.update', text: 'PDF Template Update' },
    PDF_TEMPLATE_DELETE: { value: 'pdf_template.delete', text: 'PDF Template Delete' },
    PDF_TEMPLATE_EXPORT: { value: 'pdf_template.export', text: 'PDF Template Export' },
    USER_EXPORT_CSV: { value: 'user.csv.export', text: 'User CSV Export' },
    CONFIG_LIST: { value: 'config.list', text: 'Config List' },
    CONFIG_EDIT: { value: 'config.edit', text: 'Config Edit' },
    DOCUMENT_LIST: { value: 'document.list', text: 'Document List' },
    DOCUMENT_CREATE: { value: 'document.create', text: 'Document Create' },
    DOCUMENT_EDIT: { value: 'document.edit', text: 'Document Edit' },
    DOCUMENT_DELETE: { value: 'document.delete', text: 'Document Delete' },
    SHIFT_LIST: { value: 'shift.list', text: 'Shift List' },
    SHIFT_CREATE: { value: 'shift.create', text: 'Shift Create' },
    SHIFT_EDIT: { value: 'shift.edit', text: 'Shift Edit' },
    SHIFT_EXPORT: { value: 'shift.export', text: 'Shift Export' },
    SHIFT_IMPORT: { value: 'shift.import', text: 'Shift Import' },
    SHIFT_IMPORT_ORDER: { value: 'shift.import_order', text: 'Shift Order Import' },
    SHIFT_CHECK_IN: { value: 'shift.check_in', text: 'Shift Check In' },
    SHIFT_UPDATE_SALARY: { value: 'shift.update_salary', text: 'Shift Update Salary' },
    SHIFT_UPDATE_OVERTIME: { value: 'shift.update_overtime', text: 'Shift Update Overtime' },
    SHIFT_DELETE: { value: 'shift.delete', text: 'Shift Delete' },
    WORK_HISTORY_LIST: { value: 'work_history.list', text: 'Work History List' },
    WORK_HISTORY_ADD: { value: 'work_history.add', text: 'Work History Add' },
    WORK_HISTORY_EDIT: { value: 'work_history.edit', text: 'Work History Edit' },
    WORK_HISTORY_EXPORT: { value: 'work_history.export', text: 'Work History Export' },
    WORK_HISTORY_EDIT_REQUEST_PROCESS: {
        value: 'work_history.process_edit_request',
        text: 'Work History Edit Request Process',
    },
    RECONCILIATION_WORK: { value: 'reconciliation.work', text: 'Reconciliation Work' },
    WORK_REPORT_LIST: { value: 'work_report.list', text: 'Work Report List' },
    WORK_REPORT_EXPORT: { value: 'work_report.export', text: 'Work Report Export' },
    NOTIFICATION_LIST: { value: 'notification.list', text: 'Notification List' },
    NOTIFICATION_CREATE: { value: 'notification.create', text: 'Notification Create' },
    NOTIFICATION_EDIT: { value: 'notification.edit', text: 'Notification Edit' },
    NOTIFICATION_DELETE: { value: 'notification.delete', text: 'Notification Delete' },
    ANNOUNCEMENT_LIST: { value: 'announcement.list', text: 'Announcement List' },
    ANNOUNCEMENT_CREATE: { value: 'announcement.create', text: 'Announcement Create' },
    ANNOUNCEMENT_EDIT: { value: 'announcement.edit', text: 'Announcement Edit' },
    ANNOUNCEMENT_DELETE: { value: 'announcement.delete', text: 'Announcement Delete' },
    SALARY_LIST: { value: 'salary.general.list', text: 'Salary List' },
    SALARY_COLLECT: { value: 'salary.general.collect', text: 'Salary Collect' },
    SALARY_EXPORT: { value: 'salary.general.export', text: 'Salary Export' },
    SALARY_IMPORT_DEDUCTION: { value: 'salary.general.import_deduction', text: 'Salary Import Deduction' },
    SALARY_IMPORT_TRANSFER: { value: 'salary.general.import_transfer', text: 'Salary Import Transfer' },
    SALARY_IMPORT_REMAINING: { value: 'salary.general.import_remaining', text: 'Salary Import Remaining' },
    SALARY_REQUEST_LIST: { value: 'salary.request.list', text: 'Salary Request List' },
    SALARY_REQUEST_CREATE: { value: 'salary.request.create', text: 'Salary Request Create' },
    SALARY_REQUEST_EXPORT: { value: 'salary.request.export', text: 'Salary Request Export' },
    SALARY_REQUEST_APPROVE: { value: 'salary.request.approve', text: 'Salary Request Approve' },
    SALARY_REQUEST_APPROVE_ACCOUNTANT: {
        value: 'salary.request.approve_accountant',
        text: 'Salary Request Approve Accountant',
    },
    SALARY_REQUEST_REJECT: { value: 'salary.request.reject', text: 'Salary Request Reject' },
    SALARY_REQUEST_CONFIRM_PAID: { value: 'salary.request.confirm_paid', text: 'Salary Request Confirm Paid' },
    SALARY_PROCESS_LIST: { value: 'salary.process.list', text: 'Salary Process List' },
    SALARY_PROCESS_CREATE: { value: 'salary.process.create', text: 'Salary Process Create' },
    SALARY_PROCESS_EDIT: { value: 'salary.process.edit', text: 'Salary Process Edit' },
    SALARY_PROCESS_DELETE: { value: 'salary.process.delete', text: 'Salary Process Delete' },
    EXAM_LIST: { value: 'exam.list', text: 'Exam List' },
    EXAM_CREATE: { value: 'exam.create', text: 'Exam Create' },
    EXAM_EDIT: { value: 'exam.edit', text: 'Exam Edit' },
    EXAM_DELETE: { value: 'exam.delete', text: 'Exam Delete' },
    EXAM_RESULT_LIST: { value: 'exam.result.list', text: 'Exam Result List' },
    EXAM_RESULT_EXPORT: { value: 'exam.result.export', text: 'Exam Result Export' },
    POLL_LIST: { value: 'poll.list', text: 'Poll List' },
    POLL_CREATE: { value: 'poll.create', text: 'Poll Create' },
    POLL_EDIT: { value: 'poll.edit', text: 'Poll Edit' },
    POLL_DELETE: { value: 'poll.delete', text: 'Poll Delete' },
    CHAT_VIEW: { value: 'chat.view', text: 'Chat View' },
    CHAT_CREATE: { value: 'chat.create', text: 'Chat Create' },
    CHAT_EDIT: { value: 'chat.edit', text: 'Chat Edit' },
    CHAT_ASSIGN: { value: 'chat.assign', text: 'Chat Assign' },
    CHAT_SUPPORT: { value: 'chat.support', text: 'Chat Support' },
    CHAT_DELETE_MESSAGE: { value: 'chat.delete_message', text: 'Chat Delete Message' },
    CHAT_DELETE_CONVERSATION: { value: 'chat.delete_conversation', text: 'Chat Delete Conversation' },
    GROUP_VIEW: { value: 'group.view', text: 'Group View' },
    GROUP_EDIT: { value: 'group.edit', text: 'Group Edit' },
    ADMIN_LEAVE_MANAGE: { value: 'admin_leave.manage', text: 'Admin Leave Manage' },
});

export type PermissionTreeItem = Record<string, Record<string, string[]>>;

export const getPermissionTree = (): PermissionTreeItem => {
    const tree: PermissionTreeItem = {};
    for (const key in PermissionNameEnum) {
        const item = PermissionNameEnum[key as PermissionName];
        const parts = item.value.split('.');
        if (parts.length !== 2 && parts.length !== 3) {
            continue;
        }
        if (parts.length === 2) {
            if (!tree[parts[0]]) {
                tree[parts[0]] = { general: [item.value] };
            } else {
                tree[parts[0]].general.push(item.value);
            }
        } else if (parts.length === 3) {
            if (!tree[parts[0]]) {
                tree[parts[0]] = {};
            }
            if (!tree[parts[0]][parts[1]]) {
                tree[parts[0]][parts[1]] = [item.value];
            } else {
                tree[parts[0]][parts[1]].push(item.value);
            }
        }
    }
    return tree;
};
