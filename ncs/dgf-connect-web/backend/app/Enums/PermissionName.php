<?php

namespace App\Enums;

enum PermissionName: string
{
    use EnumTrait;

    /****************************************
     * Manager                              *
     ****************************************/
    case ManagerAuth = 'manager.auth';
    case ManagerProfile = 'manager.profile';
    case ManagerAttendanceCheckIn = 'manager.attendance.check_in';

    /****************************************
     * Role                                 *
     ****************************************/
    case RoleList = 'role.list';
    case RoleCreate = 'role.create';
    case RoleEdit = 'role.edit';
    case RoleDelete = 'role.delete';

    /****************************************
     * User                                 *
     ****************************************/
    case UserList = 'user.general.list';
    case UserCreate = 'user.general.create';
    case UserEdit = 'user.general.edit';
    case UserDisable = 'user.general.disable';
    case UserEnable = 'user.general.enable';
    case UserRestore = 'user.general.restore';
    case UserSync = 'user.general.sync';
    case UserAssignRole = 'user.general.assign_role';
    case UserCreateEmployee = 'user.general.create-employee';
    case UserResetPassword = 'user.general.reset-password';

    case UserImageList = 'user.image.list';
    case UserImageUpload = 'user.image.upload';
    case UserImageDelete = 'user.image.delete';

    case UserDeviceList = 'user.device.list';
    case UserDeviceDelete = 'user.device.delete';

    case UserWorkplaceList = 'user.workplace.list';
    case UserWorkplaceAdd = 'user.workplace.add';
    case UserWorkplaceRemove = 'user.workplace.remove';

    case UserPaperList = 'user.paper.list';
    case UserPaperUpload = 'user.paper.upload';
    case UserPaperApprove = 'user.paper.approve';
    case UserPaperReject = 'user.paper.reject';

    case UserPaperDelete = 'user.paper.delete';
    case UserConfigList = 'user.config.list';
    case UserConfigEdit = 'user.config.edit';

    case UserFaceList = 'user.face.list';
    case UserFaceCreate = 'user.face.create';
    case UserFaceUpdate = 'user.face.update';
    case UserFaceDelete = 'user.face.delete';

    /****************************************
     * Workplace                            *
     ****************************************/
    case WorkplaceList = 'workplace.general.list';
    case WorkplaceCreate = 'workplace.general.create';
    case WorkplaceEdit = 'workplace.general.edit';
    case WorkplaceDelete = 'workplace.general.delete';

    case WorkplaceImageList = 'workplace.image.list';
    case WorkplaceImageUpload = 'workplace.image.upload';
    case WorkplaceImageDelete = 'workplace.image.delete';

    case WorkplacePositionList = 'workplace.position.list';
    case WorkplacePositionCreate = 'workplace.position.create';
    case WorkplacePositionEdit = 'workplace.position.edit';
    case WorkplacePositionDelete = 'workplace.position.delete';

    case WorkplaceShiftList = 'workplace.shift.list';
    case WorkplaceShiftCreate = 'workplace.shift.create';
    case WorkplaceShiftEdit = 'workplace.shift.edit';
    case WorkplaceShiftDelete = 'workplace.shift.delete';

    case WorkplaceInstructionList = 'workplace.instruction.list';
    case WorkplaceInstructionCreate = 'workplace.instruction.create';
    case WorkplaceInstructionEdit = 'workplace.instruction.edit';
    case WorkplaceInstructionDelete = 'workplace.instruction.delete';

    case WorkplaceUserList = 'workplace.user.list';
    case WorkplaceUserAdd = 'workplace.user.add';
    case WorkplaceUserRemove = 'workplace.user.remove';

    /****************************************
     * Fixed schedule                       *
     ****************************************/
    case FixedScheduleList = 'fixed_schedule.list';
    case FixedScheduleCreate = 'fixed_schedule.create';
    case FixedScheduleEdit = 'fixed_schedule.edit';
    case FixedScheduleDelete = 'fixed_schedule.delete';

    case FixedScheduleChangeConfigList = 'fixed_schedule_change_config.list';
    case FixedScheduleChangeConfigEdit = 'fixed_schedule_change_config.edit';

    case FixedScheduleChangeRequestList = 'fixed_schedule_change_request.list';
    case FixedScheduleChangeRequestProcess = 'fixed_schedule_change_request.process';

    /****************************************
     * Config                               *
     ****************************************/
    case ConfigList = 'config.list';
    case ConfigEdit = 'config.edit';

    /****************************************
     * Document                             *
     ****************************************/
    case DocumentList = 'document.list';
    case DocumentCreate = 'document.create';
    case DocumentEdit = 'document.edit';
    case DocumentDelete = 'document.delete';

    /****************************************
     * Shift                                *
     ****************************************/
    case ShiftList = 'shift.list';
    case ShiftCreate = 'shift.create';
    case ShiftEdit = 'shift.edit';
    case ShiftExport = 'shift.export';
    case ShiftImport = 'shift.import';
    case ShiftImportOrder = 'shift.import_order';
    case ShiftCheckIn = 'shift.check_in';
    case ShiftUpdateSalary = 'shift.update_salary';
    case ShiftUpdateOvertime = 'shift.update_overtime';
    case ShiftDelete = 'shift.delete';

    /****************************************
     * Work history                         *
     ****************************************/
    case WorkHistoryList = 'work_history.list';
    case WorkHistoryAdd = 'work_history.add';
    case WorkHistoryEdit = 'work_history.edit';
    case WorkHistoryExport = 'work_history.export';

    /****************************************
     * Work report                          *
     ****************************************/
    case WorkReportList = 'work_report.list';
    case WorkReportExport = 'work_report.export';

    /****************************************
     * Notification                         *
     ****************************************/
    case NotificationList = 'notification.list';
    case NotificationCreate = 'notification.create';
    case NotificationEdit = 'notification.edit';
    case NotificationDelete = 'notification.delete';

    /****************************************
     * Announcement                         *
     ****************************************/
    case AnnouncementList = 'announcement.list';
    case AnnouncementCreate = 'announcement.create';
    case AnnouncementEdit = 'announcement.edit';
    case AnnouncementDelete = 'announcement.delete';

    /****************************************
     * Salary                               *
     ****************************************/
    case SalaryList = 'salary.general.list';
    case SalaryCollect = 'salary.general.collect';
    case SalaryExport = 'salary.general.export';
    case SalaryImportDeduction = 'salary.general.import_deduction';
    case SalaryImportTransfer = 'salary.general.import_transfer';
    case SalaryImportRemaining = 'salary.general.import_remaining';

    /****************************************
     * Salary request                       *
     ****************************************/
    case SalaryRequestList = 'salary.request.list';
    case SalaryRequestCreate = 'salary.request.create';
    case SalaryRequestExport = 'salary.request.export';
    case SalaryRequestApprove = 'salary.request.approve';
    case SalaryRequestApproveAccountant = 'salary.request.approve_accountant';
    case SalaryRequestReject = 'salary.request.reject';
    case SalaryRequestConfirmPaid = 'salary.request.confirm_paid';

    /****************************************
     * Salary process                       *
     ****************************************/
    case SalaryProcessList = 'salary.process.list';
    case SalaryProcessCreate = 'salary.process.create';
    case SalaryProcessEdit = 'salary.process.edit';
    case SalaryProcessDelete = 'salary.process.delete';

    /****************************************
     * Exam                                 *
     ****************************************/
    case ExamList = 'exam.list';
    case ExamCreate = 'exam.create';
    case ExamEdit = 'exam.edit';
    case ExamDelete = 'exam.delete';

    case ExamResultList = 'exam.result.list';
    case ExamResultExport = 'exam.result.export';

    /****************************************
     * Check-in                             *
     ****************************************/
    case CheckInSpoofList = 'check_in.spoof.list';

    /****************************************
     * PDF Template                         *
     ****************************************/
    case PdfTemplateList = 'pdf_template.list';
    case PdfTemplateCreate = 'pdf_template.create';
    case PdfTemplateUpdate = 'pdf_template.update';
    case PdfTemplateDelete = 'pdf_template.delete';
    case PdfTemplateExport = 'pdf_template.export';

    /****************************************
     * CSV Export                           *
     ****************************************/
    case UserExportCsv = 'user.csv.export';

    /****************************************
     * Poll                                 *
     ****************************************/
    case PollList = 'poll.list';
    case PollCreate = 'poll.create';
    case PollEdit = 'poll.edit';
    case PollDelete = 'poll.delete';

    /****************************************
     * Chat                                 *
     ****************************************/
    case ChatView = 'chat.view';
    case ChatCreate = 'chat.create';
    case ChatEdit = 'chat.edit';
    case ChatAssign = 'chat.assign';
    case ChatSupport = 'chat.support';
    case ChatDeleteMessage = 'chat.delete_message';
    case ChatDeleteConversation = 'chat.delete_conversation';

    /****************************************
     * Group                                *
     ****************************************/
    case GroupView = 'group.view';
    case GroupEdit = 'group.edit';

    /****************************************
     * Admin Leave                          *
     ****************************************/
    case AdminLeaveManage = 'admin_leave.manage';

    /****************************************
     * Work History Edit Request            *
     ****************************************/
    case WorkHistoryEditRequestProcess = 'work_history.process_edit_request';

    /****************************************
     * Reconciliation                       *
     ****************************************/
    case ReconciliationWork = 'reconciliation.work';

    /****************************************
     * Bank Account                         *
     ****************************************/
    case BankAccountList = 'bank_account.list';
    case BankAccountApprove = 'bank_account.approve';
}
