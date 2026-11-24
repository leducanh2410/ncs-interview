<?php

namespace App\Enums;

enum ErrorCode: string
{
    /**********************************************************************
     * Common error codes (starts with E_)
     **********************************************************************/
    case BadRequest = 'E_BAD_REQUEST';
    case Unauthorized = 'E_UNAUTHORIZED';
    case Forbidden = 'E_FORBIDDEN';
    case NotFound = 'E_NOT_FOUND';
    case MethodNotAllowed = 'E_METHOD_NOT_ALLOWED';
    case UnprocessableEntity = 'E_UNPROCESSABLE_ENTITY';
    case InternalServerError = 'E_INTERNAL_SERVER_ERROR';
    case Throttle = 'E_TOO_MANY_ATTEMPTS';
    case PayloadTooLarge = 'E_PAYLOAD_TOO_LARGE';

    /**********************************************************************
     * Authentication error codes (starts with AUTH_)
     **********************************************************************/
    case AuthInvalidToken = 'AUTH_INVALID_TOKEN';
    case AuthInvalidCredentials = 'AUTH_INVALID_CREDENTIALS';
    case AuthUserDisabled = 'AUTH_USER_DISABLED';
    case AuthTfaInvalidKey = 'AUTH_TFA_INVALID_KEY';
    case AuthTfaInvalidCode = 'AUTH_TFA_INVALID_CODE';
    case AuthRequestPasswordResetThrottled = 'AUTH_REQUEST_PASSWORD_RESET_THROTTLED';
    case AuthRequestPasswordResetInvalidUser = 'AUTH_REQUEST_PASSWORD_RESET_INVALID_USER';
    case AuthRequestPasswordResetCaptchaFailed = 'AUTH_REQUEST_PASSWORD_RESET_CAPTCHA_FAILED';
    case AuthRequestPasswordResetInvalidSession = 'AUTH_REQUEST_PASSWORD_RESET_INVALID_SESSION';
    case AuthRequestPasswordResetInvalidOtp = 'AUTH_REQUEST_PASSWORD_RESET_INVALID_OTP';

    case CaptchaFailed = 'CAPTCHA_FAILED';

    /**********************************************************************
     * USER management error codes (starts with USER_)
     **********************************************************************/
    case UserPaperStatusPending = 'USER_PAPER_STATUS_PENDING';
    case UserPaperStatusApproved = 'USER_PAPER_STATUS_APPROVED';
    case UserCannotDisableSelf = 'USER_CANNOT_DISABLE_SELF';
    case UserCannotDisableGlobalAdmin = 'USER_CANNOT_DISABLE_GLOBAL_ADMIN';
    case UserCannotEnableDeleted = 'USER_CANNOT_ENABLE_DELETED';
    case UserAlreadyDisabled = 'USER_ALREADY_DISABLED';
    case UserAlreadyEnabled = 'USER_ALREADY_ENABLED';
    case UserCannotAssignRoleSelf = 'USER_CANNOT_ASSIGN_ROLE_SELF';
    case UserCannotAssignRoleGlobalAdmin = 'USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN';

    case UserEmailAlreadyExists = 'USER_EMAIL_ALREADY_EXISTS';

    /**********************************************************************
     * ROLE management error codes (starts with ROLE_)
     **********************************************************************/
    case RoleNotFound = 'ROLE_NOT_FOUND';
    case RoleUserCannotHaveOtherRoles = 'ROLE_USER_CANNOT_HAVE_OTHER_ROLES';
    case RoleCannotAddUserWithOtherRoles = 'ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES';

    /**********************************************************************
     * Registration error codes (starts with REGISTER_)
     **********************************************************************/
    case RegisterInvalidSession = 'REGISTER_INVALID_SESSION';
    case RegisterInvalidOtp = 'REGISTER_INVALID_OTP';
    case RegisterTempUserNotFound = 'REGISTER_TEMP_USER_NOT_FOUND';
    case RegisterStaffNoAlreadyExists = 'REGISTER_STAFF_NO_ALREADY_EXISTS';
    case RegisterResidenceNumberAlreadyExists = 'REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS';
    case RegisterPassportNumberAlreadyExists = 'REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS';
    case RestoreInvalidSession = 'RESTORE_INVALID_SESSION';
    case RestoreInvalidOtp = 'RESTORE_INVALID_OTP';
    case RestoreAccountNotFound = 'RESTORE_ACCOUNT_NOT_FOUND';

    /**********************************************************************
     * WORKPLACE management error codes (starts with WORKPLACE_)
     **********************************************************************/
    case WorkplaceNotFound = 'WORKPLACE_NOT_FOUND';
    case WorkplaceCannotDeleteWithShift = 'WORKPLACE_CANNOT_DELETE_WITH_SHIFT';
    case WorkplaceCannotRemoveUserWithShift = 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT';
    case WorkplaceCannotExitWithActiveSchedule = 'WORKPLACE_CANNOT_EXIT_WITH_ACTIVE_SCHEDULE';
    case WorkplaceTestMembershipMismatch = 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH';
    case WorkplaceSelfRegistrationNotAllowed = 'WORKPLACE_SELF_REGISTRATION_NOT_ALLOWED';

    /**********************************************************************
     * SHIFT management error codes (starts with SHIFT_)
     **********************************************************************/
    case ShiftAlreadyExists = 'SHIFT_ALREADY_EXISTS';
    case ShiftAlreadyEnded = 'SHIFT_ALREADY_ENDED';

    case ShiftNotEnded = 'SHIFT_NOT_ENDED';
    case ShiftCannotCreateInactive = 'SHIFT_CANNOT_CREATE_INACTIVE';
    case ShiftUpdateInvalidFixedPending = 'SHIFT_UPDATE_INVALID_FIXED_PENDING';
    case ShiftNotYetApproved = 'SHIFT_NOT_YET_APPROVED';
    case ShiftUserNotInWorkplace = 'SHIFT_USER_NOT_IN_WORKPLACE';
    case ShiftCsvEmpty = 'SHIFT_CSV_EMPTY';
    case ShiftCsvInvalidHeader = 'SHIFT_CSV_INVALID_HEADER';
    case ShiftCsvInvalidRow = 'SHIFT_CSV_INVALID_ROW';
    case ShiftCsvInvalidStaffNo = 'SHIFT_CSV_INVALID_STAFF_NO';
    case ShiftCsvInvalidWorkplacePosition = 'SHIFT_CSV_INVALID_WORKPLACE_POSITION';
    case ShiftOrderFileInvalidFormat = 'SHIFT_ORDER_FILE_INVALID_FORMAT';
    case ShiftOrderFileHasErrors = 'SHIFT_ORDER_FILE_HAS_ERRORS';
    case ShiftOrderBatchNotPending = 'SHIFT_ORDER_BATCH_NOT_PENDING';

    case ShiftAddFailedNoPosition = 'SHIFT_ADD_INVALID_POSITION';
    case ShiftUserNotFound = 'SHIFT_USER_NOT_FOUND';
    case ShiftUserInvalidStatus = 'SHIFT_USER_INVALID_STATUS';
    case ShiftUserAbsentSalaryPaid = 'SHIFT_USER_ABSENT_SALARY_PAID';
    case ShiftUserAbsentSalaryRequested = 'SHIFT_USER_ABSENT_SALARY_REQUESTED';
    case ShiftCannotDeleteHasAttendance = 'SHIFT_CANNOT_DELETE_HAS_ATTENDANCE';
    case AbsentNoticeDeadlinePassed = 'ABSENT_NOTICE_DEADLINE_PASSED';
    case AbsentNoticeAlreadyExists = 'ABSENT_NOTICE_ALREADY_EXISTS';
    case AbsentNoticeShiftNotApproved = 'ABSENT_NOTICE_SHIFT_NOT_APPROVED';

    /**********************************************************************
     * NOTIFICATION management error codes (starts with NOTIFICATION_)
     **********************************************************************/
    case NotificationAlreadySent = 'NOTIFICATION_ALREADY_SENT';

    /**********************************************************************
     * USER FACE management error codes (starts with USER_FACE_)
     **********************************************************************/
    case UserFaceAlreadyExists = 'USER_FACE_ALREADY_EXISTS';
    case UserFaceNoApprovedPortrait = 'USER_FACE_NO_APPROVED_PORTRAIT';
    case UserFaceRegistrationFailed = 'USER_FACE_REGISTRATION_FAILED';
    case UserFaceRemovalFailed = 'USER_FACE_REMOVAL_FAILED';
    case UserFaceUpdateFailed = 'USER_FACE_UPDATE_FAILED';
    case UserFaceApprovalFailed = 'USER_FACE_APPROVAL_FAILED';

    /**********************************************************************
     * USER PAPER STEP error codes (starts with USER_PAPER_STEP_)
     **********************************************************************/
    case UserPaperStepLocked = 'USER_PAPER_STEP_LOCKED';
    case UserPaperStepChanged = 'USER_PAPER_STEP_CHANGED';
    case UserPaperStepNotUploaded = 'USER_PAPER_STEP_NOT_UPLOADED';
    case UserPaperStepNotConfirmable = 'USER_PAPER_STEP_NOT_CONFIRMABLE';
    case UserPaperStepOcrRequiredFieldsMissing = 'USER_PAPER_STEP_OCR_REQUIRED_FIELDS_MISSING';
    case UserPaperStepOcrStillProcessing = 'USER_PAPER_STEP_OCR_STILL_PROCESSING';
    case UserPaperSubmissionIncomplete = 'USER_PAPER_SUBMISSION_INCOMPLETE';
    case UserPaperStudentCardExpiryMissing = 'USER_PAPER_STUDENT_CARD_EXPIRY_MISSING';
    case UserPaperFieldRequired = 'USER_PAPER_FIELD_REQUIRED';
    case UserPaperFieldInvalidDate = 'USER_PAPER_FIELD_INVALID_DATE';
    case UserPaperFieldInvalidValue = 'USER_PAPER_FIELD_INVALID_VALUE';
    case UserPaperFieldTooLong = 'USER_PAPER_FIELD_TOO_LONG';
    case UserPaperResidenceNumberInvalidLength = 'USER_PAPER_RESIDENCE_NUMBER_INVALID_LENGTH';
    case UserPaperFileRequired = 'USER_PAPER_FILE_REQUIRED';
    case UserPaperFileInvalidType = 'USER_PAPER_FILE_INVALID_TYPE';
    case UserPaperFileTooLarge = 'USER_PAPER_FILE_TOO_LARGE';
    case UserPaperFileTooMany = 'USER_PAPER_FILE_TOO_MANY';
    case UserPaperFileUploadFailed = 'USER_PAPER_FILE_UPLOAD_FAILED';
    case UserPaperFileUnreadable = 'USER_PAPER_FILE_UNREADABLE';
    case UserPaperFileWrongStep = 'USER_PAPER_FILE_WRONG_STEP';

    /**********************************************************************
     * Check in check out error codes
     **********************************************************************/
    case CheckInInvalidToken = 'CHECK_IN_INVALID_TOKEN';
    case CheckInUserNotCheckOut = 'CHECK_IN_USER_NOT_CHECKED_OUT';
    case CheckOutUserNotCheckIn = 'CHECK_OUT_USER_NOT_CHECKED_IN';
    case CheckInDeviceAlreadyRegistered = 'CHECK_IN_DEVICE_ALREADY_REGISTERED';
    case CheckInDeviceNotFound = 'CHECK_IN_DEVICE_NOT_FOUND';
    case CheckInShiftNotFound = 'CHECK_IN_SHIFT_NOT_FOUND';
    case CheckInAlreadyCheckedIn = 'CHECK_IN_ALREADY_CHECKED_IN';
    case CheckInTooSoonOrTooLate = 'CHECK_IN_TOO_SOON_OR_TOO_LATE';
    case CheckInInvalidLocation = 'CHECK_IN_INVALID_LOCATION';
    case CheckOutShiftNotFound = 'CHECK_OUT_SHIFT_NOT_FOUND';
    case CheckOutAlreadyCheckedOut = 'CHECK_OUT_ALREADY_CHECKED_OUT';
    case CheckOutTooSoonOrTooLate = 'CHECK_OUT_TOO_SOON_OR_TOO_LATE';
    case CheckOutInvalidLocation = 'CHECK_OUT_INVALID_LOCATION';
    case CheckOutTimeInvalid = 'CHECK_OUT_TIME_INVALID';
    case CheckInNoFaceRegistered = 'CHECK_IN_NO_FACE_REGISTERED';
    case CheckOutNoFaceRegistered = 'CHECK_OUT_NO_FACE_REGISTERED';
    case CheckInFaceVerificationFailed = 'CHECK_IN_FACE_VERIFICATION_FAILED';
    case CheckOutFaceVerificationFailed = 'CHECK_OUT_FACE_VERIFICATION_FAILED';
    case CheckInWorkplaceOutOfScope = 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE';
    case AttendanceReasonNotRequired = 'ATTENDANCE_REASON_NOT_REQUIRED';
    case AttendanceReasonAlreadySubmitted = 'ATTENDANCE_REASON_ALREADY_SUBMITTED';

    /**********************************************************************
     * Admin attendance error codes
     **********************************************************************/
    case AdminAttendanceNotAssignedWorkplace = 'ADMIN_ATTENDANCE_NOT_ASSIGNED_WORKPLACE';
    case AdminAttendanceNoNearbyWorkplace = 'ADMIN_ATTENDANCE_NO_NEARBY_WORKPLACE';
    case AdminAttendanceNotCheckedIn = 'ADMIN_ATTENDANCE_NOT_CHECKED_IN';

    /**********************************************************************
     * Schedule error codes (starts with SCHEDULE_)
     **********************************************************************/
    case ScheduleMaxShiftsExceeded = 'SCHEDULE_MAX_SHIFTS_EXCEEDED';
    case ScheduleShiftOverlap = 'SCHEDULE_SHIFT_OVERLAP';
    case ScheduleShiftAlreadyStarted = 'SCHEDULE_SHIFT_ALREADY_STARTED';
    case ScheduleShiftAlreadyEnded = 'SCHEDULE_SHIFT_ALREADY_ENDED';
    case ScheduleMultipleShiftsSameDay = 'SCHEDULE_MULTIPLE_SHIFTS_SAME_DAY';
    case ScheduleMaxWorkHoursExceeded = 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED';
    case ScheduleExpiredStudentCard = 'SCHEDULE_EXPIRED_STUDENT_CARD';
    case ScheduleExpiredResidenceCard = 'SCHEDULE_EXPIRED_RESIDENCE_CARD';
    case ScheduleExpiredPassport = 'SCHEDULE_EXPIRED_PASSPORT';
    case ScheduleShiftMaxRegisteredTemp = 'SCHEDULE_SHIFT_MAX_REGISTERED_TEMP';
    case ScheduleUserDisabled = 'SCHEDULE_USER_DISABLED';
    case ScheduleUserPaperRejected = 'SCHEDULE_USER_PAPER_REJECTED';
    case ScheduleUserMissingStaffNo = 'SCHEDULE_USER_MISSING_STAFF_NO';
    case ScheduleMinRestHoursViolated = 'SCHEDULE_MIN_REST_HOURS_VIOLATED';
    case ScheduleUserNotMember = 'SCHEDULE_USER_NOT_MEMBER';
    case ScheduleCapacityExceeded = 'SCHEDULE_CAPACITY_EXCEEDED';

    /**********************************************************************
     * Salary error codes (starts with SALARY_)
     **********************************************************************/
    case SalaryRequestExists = 'SALARY_REQUEST_EXISTS';
    case SalaryRequestAmountTooHigh = 'SALARY_REQUEST_AMOUNT_TOO_HIGH';
    case SalaryRequestInvalidAmount = 'SALARY_REQUEST_INVALID_AMOUNT';
    case SalaryCsvMalformed = 'SALARY_CSV_MALFORMED';
    case SalaryCsvInvalidStaffNo = 'SALARY_CSV_INVALID_STAFF_NO';
    case SalaryCsvInvalidAmount = 'SALARY_CSV_INVALID_AMOUNT';
    case SalaryCsvInvalidMonth = 'SALARY_CSV_INVALID_MONTH';
    case SalaryCsvSalaryNotFound = 'SALARY_CSV_SALARY_NOT_FOUND';
    case SalaryCsvAmountTooHigh = 'SALARY_CSV_AMOUNT_TOO_HIGH';
    case SalaryTransferCsvInvalidStaffNo = 'SALARY_TRANSFER_CSV_INVALID_STAFF_NO';
    case SalaryTransferCsvInvalidAmount = 'SALARY_TRANSFER_CSV_INVALID_AMOUNT';
    case SalaryTransferCsvRequestNotFound = 'SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND';
    case SalaryTransferCsvAmountNotMatch = 'SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH';
    case SalaryTransferCsvInvalidTime = 'SALARY_TRANSFER_CSV_INVALID_TIME';
    case SalaryTransferCsvAmountTooHigh = 'SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH';

    /**********************************************************************
     * Salary error codes (starts with EXAM_)
     **********************************************************************/
    case ExamTimeOutOfRange = 'EXAM_TIME_OUT_OF_RANGE';
    case ExamAlreadyPassed = 'EXAM_ALREADY_PASSED';
    case ExamRetryLimitExceeded = 'EXAM_RETRY_LIMIT_EXCEEDED';
    case ExamSubmitFailedNoSessionFound = 'EXAM_SUBMIT_FAILED_NO_SESSION_FOUND';
    case ExamSubmitFailedSessionExpired = 'EXAM_SUBMIT_FAILED_SESSION_EXPIRED';
    case ExamResultNotAvailable = 'EXAM_RESULT_NOT_AVAILABLE';

    /**********************************************************************
     * PDF Template error codes (starts with PDF_TEMPLATE_)
     **********************************************************************/
    case PdfTemplateLocked = 'PDF_TEMPLATE_LOCKED';
    case PdfExportExcelInvalidFormat = 'PDF_EXPORT_EXCEL_INVALID_FORMAT';
    case PdfExportExcelEmpty = 'PDF_EXPORT_EXCEL_EMPTY';
    case PdfExportExcelTooManyRows = 'PDF_EXPORT_EXCEL_TOO_MANY_ROWS';
    case PdfExportExcelNoUsersMatched = 'PDF_EXPORT_EXCEL_NO_USERS_MATCHED';

    /**********************************************************************
     * Poll error codes (starts with POLL_)
     **********************************************************************/
    case PollInactive = 'POLL_INACTIVE';
    case PollExpired = 'POLL_EXPIRED';

    /**********************************************************************
     * Chat error codes (starts with CHAT_)
     **********************************************************************/
    case ChatInvalidCursor = 'CHAT_INVALID_CURSOR';
    case GuestSessionAlreadyExists = 'GUEST_SESSION_ALREADY_EXISTS';
    case ChatMessageNotInConversation = 'CHAT_MESSAGE_NOT_IN_CONVERSATION';
    case ChatConversationClosed = 'CHAT_CONVERSATION_CLOSED';
    case ChatAlreadyClosed = 'CHAT_ALREADY_CLOSED';
    case ChatAlreadyOpen = 'CHAT_ALREADY_OPEN';
    case ChatReopenNotAllowed = 'CHAT_REOPEN_NOT_ALLOWED';
    case ChatShiftDeleted = 'CHAT_SHIFT_DELETED';
    case ChatMessageContainsBannedKeyword = 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD';
    case ChatParticipantOutOfScope = 'CHAT_PARTICIPANT_OUT_OF_SCOPE';
    case ChatParticipantAlreadyInScope = 'CHAT_PARTICIPANT_ALREADY_IN_SCOPE';
    case ChatParticipantNotExtra = 'CHAT_PARTICIPANT_NOT_EXTRA';
    case ChatSystemConversationImmutable = 'CHAT_SYSTEM_CONVERSATION_IMMUTABLE';

    /**********************************************************************
     * Shift Planning error codes (starts with SHIFT_PLANNING_)
     **********************************************************************/
    case ShiftPlanningLocked = 'SHIFT_PLANNING_LOCKED';
    case ShiftPlanningLockRequired = 'SHIFT_PLANNING_LOCK_REQUIRED';
    case ShiftPlanningDateTooSoon = 'SHIFT_PLANNING_DATE_TOO_SOON';
    case ShiftPlanningShiftMissing = 'SHIFT_PLANNING_SHIFT_MISSING';

    /**********************************************************************
     * Fixed schedule change request error codes (starts with FIXED_SCHEDULE_)
     **********************************************************************/
    case FixedScheduleWorkplaceRequired = 'FIXED_SCHEDULE_WORKPLACE_REQUIRED';
    case FixedScheduleWindowClosed = 'FIXED_SCHEDULE_WINDOW_CLOSED';
    case FixedScheduleRequestAlreadyPending = 'FIXED_SCHEDULE_REQUEST_ALREADY_PENDING';
    case FixedScheduleNoChangedDays = 'FIXED_SCHEDULE_NO_CHANGED_DAYS';
    case FixedScheduleInvalidShift = 'FIXED_SCHEDULE_INVALID_SHIFT';
    case FixedScheduleRequestNotPending = 'FIXED_SCHEDULE_REQUEST_NOT_PENDING';

    /**********************************************************************
     * Admin leave request error codes (starts with ADMIN_LEAVE_)
     **********************************************************************/
    case AdminLeaveRequestNotPending = 'ADMIN_LEAVE_REQUEST_NOT_PENDING';

    /**********************************************************************
     * Work history edit request error codes (starts with WORK_HISTORY_EDIT_REQUEST_)
     **********************************************************************/
    case WorkHistoryEditRequestAlreadyPending = 'WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING';
    case WorkHistoryEditRequestNoChanges = 'WORK_HISTORY_EDIT_REQUEST_NO_CHANGES';
    case WorkHistoryEditRequestNotPending = 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING';
    case WorkHistoryEditRequestRejectReasonRequired = 'WORK_HISTORY_EDIT_REQUEST_REJECT_REASON_REQUIRED';
    case WorkHistoryEditRequestDataChanged = 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED';
    case WorkHistoryEditRequestInvalidTimeOrder = 'WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER';
    case WorkHistoryEditRequestPendingCheckOutChange = 'WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE';
    case WorkHistoryEditRequestImportInvalidFormat = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT';
    case WorkHistoryEditRequestImportRowError = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR';
    case WorkHistoryEditRequestImportNoChanges = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES';
    // Per-row reason codes embedded in WorkHistoryEditRequestImportRowError's `errors[].reason`
    // (and, for a commit-time conflict, on the single aborting row) - never returned as the
    // top-level `error` value on their own, mirroring how Salary CSV import embeds a specific
    // ErrorCode per invalid line inside the generic import-failed response.
    case WorkHistoryEditRequestImportRowIdMissing = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_MISSING';
    case WorkHistoryEditRequestImportRowIdDuplicate = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE';
    case WorkHistoryEditRequestImportRowIdNotFound = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND';
    case WorkHistoryEditRequestImportRowAlreadyPending = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING';
    case WorkHistoryEditRequestImportRowCrossWorkplace = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE';
    case WorkHistoryEditRequestImportRowInvalidValue = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE';
    case WorkHistoryEditRequestImportRowCheckOutBeforeCheckIn = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN';
    case WorkHistoryEditRequestImportRowImplausibleDuration = 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION';

    /**********************************************************************
     * Attendance reconciliation error codes (starts with RECONCILIATION_)
     **********************************************************************/
    case ReconciliationUnknownSource = 'RECONCILIATION_UNKNOWN_SOURCE';
    case ReconciliationInvalidFormat = 'RECONCILIATION_INVALID_FORMAT';
    case ReconciliationNoPunchRows = 'RECONCILIATION_NO_PUNCH_ROWS';
    case ReconciliationBatchNotDraft = 'RECONCILIATION_BATCH_NOT_DRAFT';
    case ReconciliationBatchNotConfirmed = 'RECONCILIATION_BATCH_NOT_CONFIRMED';
    case ReconciliationDecisionsIncomplete = 'RECONCILIATION_DECISIONS_INCOMPLETE';
    case ReconciliationDuplicateCheckIn = 'RECONCILIATION_DUPLICATE_CHECK_IN';
    case ReconciliationStale = 'RECONCILIATION_STALE';
    case ReconciliationNothingToExport = 'RECONCILIATION_NOTHING_TO_EXPORT';

    /**********************************************************************
     * Admin paper upload error codes (starts with ADMIN_PAPER_UPLOAD_)
     **********************************************************************/
    case AdminPaperUploadOcrPending = 'ADMIN_PAPER_UPLOAD_OCR_PENDING';
    case AdminPaperUploadOcrUnreadable = 'ADMIN_PAPER_UPLOAD_OCR_UNREADABLE';
    case AdminPaperUploadAlreadySubmitted = 'ADMIN_PAPER_UPLOAD_ALREADY_SUBMITTED';

    /**********************************************************************
     * Bank account error codes (starts with BANK_ACCOUNT_)
     **********************************************************************/
    case BankAccountLocked = 'BANK_ACCOUNT_LOCKED';
    case BankAccountNotConfirmable = 'BANK_ACCOUNT_NOT_CONFIRMABLE';
    case BankAccountChanged = 'BANK_ACCOUNT_CHANGED';
    case BankAccountOcrBudgetSpent = 'BANK_ACCOUNT_OCR_BUDGET_SPENT';
    case BankAccountTypeNotAllowed = 'BANK_ACCOUNT_TYPE_NOT_ALLOWED';
    case BankAccountInvalidState = 'BANK_ACCOUNT_INVALID_STATE';
    case BankAccountPhoneRequired = 'BANK_ACCOUNT_PHONE_REQUIRED';
}
