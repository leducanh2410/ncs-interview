export type ErrorCode =
    | 'E_BAD_REQUEST'
    | 'E_UNAUTHORIZED'
    | 'E_FORBIDDEN'
    | 'E_NOT_FOUND'
    | 'E_METHOD_NOT_ALLOWED'
    | 'E_UNPROCESSABLE_ENTITY'
    | 'E_INTERNAL_SERVER_ERROR'
    | 'AUTH_INVALID_TOKEN'
    | 'AUTH_INVALID_CREDENTIALS'
    | 'AUTH_USER_DISABLED'
    | 'AUTH_TFA_INVALID_KEY'
    | 'AUTH_TFA_INVALID_CODE'
    | 'AUTH_REQUEST_PASSWORD_RESET_THROTTLED'
    | 'AUTH_REQUEST_PASSWORD_RESET_INVALID_USER'
    | 'AUTH_REQUEST_PASSWORD_RESET_CAPTCHA_FAILED'
    | 'REGISTER_INVALID_SESSION'
    | 'REGISTER_TEMP_USER_NOT_FOUND'
    | 'REGISTER_STAFF_NO_ALREADY_EXISTS'
    | 'REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS'
    | 'REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS'
    | 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED'
    | 'SHIFT_USER_ABSENT_SALARY_PAID'
    | 'SHIFT_USER_ABSENT_SALARY_REQUESTED'
    | 'USER_CANNOT_ASSIGN_ROLE_SELF'
    | 'USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN'
    | 'USER_EMAIL_ALREADY_EXISTS'
    | 'USER_ALREADY_DISABLED'
    | 'USER_ALREADY_ENABLED'
    | 'ROLE_USER_CANNOT_HAVE_OTHER_ROLES'
    | 'ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES'
    | 'WORKPLACE_CANNOT_DELETE_WITH_SHIFT'
    | 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT'
    | 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH'
    | 'SHIFT_ALREADY_EXISTS'
    | 'SHIFT_CANNOT_DELETE_HAS_ATTENDANCE'
    | 'SHIFT_CANNOT_CREATE_INACTIVE'
    | 'SHIFT_CSV_EMPTY'
    | 'SHIFT_CSV_INVALID_HEADER'
    | 'SHIFT_CSV_INVALID_ROW'
    | 'NOTIFICATION_ALREADY_SENT'
    | 'BANK_ACCOUNT_LOCKED'
    | 'BANK_ACCOUNT_NOT_CONFIRMABLE'
    | 'BANK_ACCOUNT_CHANGED'
    | 'BANK_ACCOUNT_OCR_BUDGET_SPENT'
    | 'BANK_ACCOUNT_TYPE_NOT_ALLOWED'
    | 'BANK_ACCOUNT_INVALID_STATE'
    | 'BANK_ACCOUNT_PHONE_REQUIRED'
    | 'CHECK_OUT_USER_NOT_CHECKED_IN'
    | 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE'
    | 'SALARY_REQUEST_EXISTS'
    | 'SALARY_CSV_MALFORMED'
    | 'SALARY_CSV_INVALID_STAFF_NO'
    | 'SALARY_CSV_INVALID_AMOUNT'
    | 'SALARY_CSV_AMOUNT_TOO_HIGH'
    | 'SALARY_CSV_INVALID_MONTH'
    | 'SALARY_CSV_SALARY_NOT_FOUND'
    | 'SALARY_TRANSFER_CSV_INVALID_STAFF_NO'
    | 'SALARY_TRANSFER_CSV_INVALID_AMOUNT'
    | 'SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND'
    | 'SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH'
    | 'SALARY_TRANSFER_CSV_INVALID_TIME'
    | 'SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH'
    | 'USER_FACE_ALREADY_EXISTS'
    | 'USER_FACE_NO_APPROVED_PORTRAIT'
    | 'USER_FACE_REGISTRATION_FAILED'
    | 'USER_FACE_REMOVAL_FAILED'
    | 'USER_FACE_UPDATE_FAILED'
    | 'USER_FACE_APPROVAL_FAILED'
    | 'USER_PAPER_STUDENT_CARD_EXPIRY_MISSING'
    | 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD'
    | 'CHAT_SHIFT_DELETED'
    | 'EXAM_RESULT_NOT_AVAILABLE'
    | 'ADMIN_LEAVE_REQUEST_NOT_PENDING'
    | 'ABSENT_NOTICE_SHIFT_NOT_APPROVED'
    | 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED'
    | 'WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER'
    | 'WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE'
    | 'WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING'
    | 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_MISSING'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN'
    | 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION'
    | 'RECONCILIATION_UNKNOWN_SOURCE'
    | 'RECONCILIATION_INVALID_FORMAT'
    | 'RECONCILIATION_NO_PUNCH_ROWS'
    | 'RECONCILIATION_BATCH_NOT_DRAFT'
    | 'RECONCILIATION_BATCH_NOT_CONFIRMED'
    | 'RECONCILIATION_DECISIONS_INCOMPLETE'
    | 'RECONCILIATION_DUPLICATE_CHECK_IN'
    | 'RECONCILIATION_STALE'
    | 'RECONCILIATION_NOTHING_TO_EXPORT';

export const ErrorCodeEnum: { [key in ErrorCode]: { value: string; text: string } } = Object.freeze({
    E_BAD_REQUEST: { value: 'E_BAD_REQUEST', text: 'Bad Request' },
    E_UNAUTHORIZED: { value: 'E_UNAUTHORIZED', text: 'Unauthorized' },
    E_FORBIDDEN: { value: 'E_FORBIDDEN', text: 'Forbidden' },
    E_NOT_FOUND: { value: 'E_NOT_FOUND', text: 'Not Found' },
    E_METHOD_NOT_ALLOWED: { value: 'E_METHOD_NOT_ALLOWED', text: 'Method Not Allowed' },
    E_UNPROCESSABLE_ENTITY: { value: 'E_UNPROCESSABLE_ENTITY', text: 'Unprocessable Entity' },
    E_INTERNAL_SERVER_ERROR: { value: 'E_INTERNAL_SERVER_ERROR', text: 'Internal Server Error' },
    AUTH_INVALID_TOKEN: { value: 'AUTH_INVALID_TOKEN', text: 'Invalid Token' },
    AUTH_INVALID_CREDENTIALS: { value: 'AUTH_INVALID_CREDENTIALS', text: 'Invalid Credentials' },
    AUTH_USER_DISABLED: { value: 'AUTH_USER_DISABLED', text: 'User Disabled' },
    AUTH_TFA_INVALID_KEY: { value: 'AUTH_TFA_INVALID_KEY', text: 'Invalid TFA Key' },
    AUTH_TFA_INVALID_CODE: { value: 'AUTH_TFA_INVALID_CODE', text: 'Invalid TFA Code' },
    AUTH_REQUEST_PASSWORD_RESET_THROTTLED: {
        value: 'AUTH_REQUEST_PASSWORD_RESET_THROTTLED',
        text: 'Request Password Reset Throttled',
    },
    AUTH_REQUEST_PASSWORD_RESET_INVALID_USER: {
        value: 'AUTH_REQUEST_PASSWORD_RESET_INVALID_USER',
        text: 'Request Password Reset Invalid User',
    },
    AUTH_REQUEST_PASSWORD_RESET_CAPTCHA_FAILED: {
        value: 'AUTH_REQUEST_PASSWORD_RESET_CAPTCHA_FAILED',
        text: 'Request Password Reset Captcha Failed',
    },
    REGISTER_INVALID_SESSION: { value: 'REGISTER_INVALID_SESSION', text: 'Invalid registration session' },
    REGISTER_TEMP_USER_NOT_FOUND: { value: 'REGISTER_TEMP_USER_NOT_FOUND', text: 'Temp user not found' },
    REGISTER_STAFF_NO_ALREADY_EXISTS: {
        value: 'REGISTER_STAFF_NO_ALREADY_EXISTS',
        text: 'Staff number already exists',
    },
    REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS: {
        value: 'REGISTER_RESIDENCE_NUMBER_ALREADY_EXISTS',
        text: 'Residence number already exists',
    },
    REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS: {
        value: 'REGISTER_PASSPORT_NUMBER_ALREADY_EXISTS',
        text: 'Passport number already exists',
    },

    SCHEDULE_MAX_WORK_HOURS_EXCEEDED: {
        value: 'SCHEDULE_MAX_WORK_HOURS_EXCEEDED',
        text: 'Weekly work hour limit exceeded',
    },
    SHIFT_USER_ABSENT_SALARY_PAID: {
        value: 'SHIFT_USER_ABSENT_SALARY_PAID',
        text: 'Salary for this month has already been paid',
    },
    SHIFT_USER_ABSENT_SALARY_REQUESTED: {
        value: 'SHIFT_USER_ABSENT_SALARY_REQUESTED',
        text: 'Salary for this month has an active payment request',
    },

    USER_CANNOT_ASSIGN_ROLE_SELF: { value: 'USER_CANNOT_ASSIGN_ROLE_SELF', text: 'Cannot assign role to self' },
    USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN: {
        value: 'USER_CANNOT_ASSIGN_ROLE_GLOBAL_ADMIN',
        text: 'Cannot assign role to global admin',
    },
    USER_EMAIL_ALREADY_EXISTS: { value: 'USER_EMAIL_ALREADY_EXISTS', text: 'Email already exists' },
    USER_ALREADY_DISABLED: { value: 'USER_ALREADY_DISABLED', text: 'This account has already been locked' },
    USER_ALREADY_ENABLED: { value: 'USER_ALREADY_ENABLED', text: 'This account has already been unlocked' },
    ROLE_USER_CANNOT_HAVE_OTHER_ROLES: {
        value: 'ROLE_USER_CANNOT_HAVE_OTHER_ROLES',
        text: 'User with User role cannot have other roles',
    },
    ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES: {
        value: 'ROLE_CANNOT_ADD_USER_WITH_OTHER_ROLES',
        text: 'Cannot add User role when user has other roles',
    },

    WORKPLACE_CANNOT_DELETE_WITH_SHIFT: {
        value: 'WORKPLACE_CANNOT_DELETE_WITH_SHIFT',
        text: 'Cannot delete workplace with shift',
    },
    WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT: {
        value: 'WORKPLACE_CANNOT_REMOVE_USER_WITH_SHIFT',
        text: 'Cannot remove user with shift',
    },
    WORKPLACE_TEST_MEMBERSHIP_MISMATCH: {
        value: 'WORKPLACE_TEST_MEMBERSHIP_MISMATCH',
        text: 'Only test employees can join test workplaces',
    },

    SHIFT_ALREADY_EXISTS: { value: 'SHIFT_ALREADY_EXISTS', text: 'Shift already exists' },
    SHIFT_CANNOT_DELETE_HAS_ATTENDANCE: {
        value: 'SHIFT_CANNOT_DELETE_HAS_ATTENDANCE',
        text: 'Shift cannot be deleted because it already has attendance data',
    },
    SHIFT_CANNOT_CREATE_INACTIVE: { value: 'SHIFT_CANNOT_CREATE_INACTIVE', text: 'Cannot create inactive shift' },
    SHIFT_CSV_EMPTY: { value: 'SHIFT_CSV_EMPTY', text: 'CSV file is empty' },
    SHIFT_CSV_INVALID_HEADER: { value: 'SHIFT_CSV_INVALID_HEADER', text: 'CSV file has invalid header' },
    SHIFT_CSV_INVALID_ROW: { value: 'SHIFT_CSV_INVALID_ROW', text: 'CSV file has invalid row' },

    NOTIFICATION_ALREADY_SENT: { value: 'NOTIFICATION_ALREADY_SENT', text: 'Notification already sent' },
    BANK_ACCOUNT_LOCKED: { value: 'BANK_ACCOUNT_LOCKED', text: 'Bank account locked' },
    BANK_ACCOUNT_NOT_CONFIRMABLE: { value: 'BANK_ACCOUNT_NOT_CONFIRMABLE', text: 'Bank account not confirmable' },
    BANK_ACCOUNT_CHANGED: { value: 'BANK_ACCOUNT_CHANGED', text: 'Bank account changed' },
    BANK_ACCOUNT_OCR_BUDGET_SPENT: { value: 'BANK_ACCOUNT_OCR_BUDGET_SPENT', text: 'Bank account OCR budget spent' },
    BANK_ACCOUNT_TYPE_NOT_ALLOWED: { value: 'BANK_ACCOUNT_TYPE_NOT_ALLOWED', text: 'Bank account type not allowed' },
    BANK_ACCOUNT_INVALID_STATE: { value: 'BANK_ACCOUNT_INVALID_STATE', text: 'Bank account invalid state' },
    BANK_ACCOUNT_PHONE_REQUIRED: { value: 'BANK_ACCOUNT_PHONE_REQUIRED', text: 'Phone number required' },

    CHECK_OUT_USER_NOT_CHECKED_IN: { value: 'CHECK_OUT_USER_NOT_CHECKED_IN', text: 'User not checked in' },
    CHECK_IN_WORKPLACE_OUT_OF_SCOPE: {
        value: 'CHECK_IN_WORKPLACE_OUT_OF_SCOPE',
        text: 'Workplace out of scope',
    },

    SALARY_REQUEST_EXISTS: { value: 'SALARY_REQUEST_EXISTS', text: 'Salary request already exists' },

    SALARY_CSV_MALFORMED: { value: 'SALARY_CSV_MALFORMED', text: 'CSV file is malformed' },
    SALARY_CSV_INVALID_STAFF_NO: { value: 'SALARY_CSV_INVALID_STAFF_NO', text: 'CSV file has invalid staff number' },
    SALARY_CSV_INVALID_AMOUNT: { value: 'SALARY_CSV_INVALID_AMOUNT', text: 'CSV file has invalid amount' },
    SALARY_CSV_AMOUNT_TOO_HIGH: { value: 'SALARY_CSV_AMOUNT_TOO_HIGH', text: 'CSV file has amount too high' },
    SALARY_CSV_INVALID_MONTH: { value: 'SALARY_CSV_INVALID_MONTH', text: 'CSV file has invalid month' },
    SALARY_CSV_SALARY_NOT_FOUND: {
        value: 'SALARY_CSV_SALARY_NOT_FOUND',
        text: 'Salary for this month has not been calculated yet',
    },

    SALARY_TRANSFER_CSV_INVALID_STAFF_NO: {
        value: 'SALARY_TRANSFER_CSV_INVALID_STAFF_NO',
        text: 'Transfer CSV file has invalid staff number',
    },
    SALARY_TRANSFER_CSV_INVALID_AMOUNT: {
        value: 'SALARY_TRANSFER_CSV_INVALID_AMOUNT',
        text: 'Transfer CSV file has invalid amount',
    },
    SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND: {
        value: 'SALARY_TRANSFER_CSV_REQUEST_NOT_FOUND',
        text: 'Transfer CSV file request not found',
    },
    SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH: {
        value: 'SALARY_TRANSFER_CSV_AMOUNT_NOT_MATCH',
        text: 'Transfer CSV file amount does not match',
    },
    SALARY_TRANSFER_CSV_INVALID_TIME: {
        value: 'SALARY_TRANSFER_CSV_INVALID_TIME',
        text: 'Transfer CSV file has invalid time',
    },
    SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH: {
        value: 'SALARY_TRANSFER_CSV_AMOUNT_TOO_HIGH',
        text: 'Transfer CSV file has amount too high',
    },

    CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD: {
        value: 'CHAT_MESSAGE_CONTAINS_BANNED_KEYWORD',
        text: 'Message contains a banned keyword',
    },
    CHAT_SHIFT_DELETED: {
        value: 'CHAT_SHIFT_DELETED',
        text: 'This chat cannot be reopened because its shift was deleted',
    },

    USER_FACE_ALREADY_EXISTS: { value: 'USER_FACE_ALREADY_EXISTS', text: 'User face already registered' },
    USER_FACE_NO_APPROVED_PORTRAIT: {
        value: 'USER_FACE_NO_APPROVED_PORTRAIT',
        text: 'No approved portrait image found',
    },
    USER_FACE_REGISTRATION_FAILED: {
        value: 'USER_FACE_REGISTRATION_FAILED',
        text: 'Face registration failed',
    },
    USER_FACE_REMOVAL_FAILED: { value: 'USER_FACE_REMOVAL_FAILED', text: 'Face removal failed' },
    USER_FACE_UPDATE_FAILED: { value: 'USER_FACE_UPDATE_FAILED', text: 'Face update failed' },
    USER_FACE_APPROVAL_FAILED: { value: 'USER_FACE_APPROVAL_FAILED', text: 'Face approval failed' },

    USER_PAPER_STUDENT_CARD_EXPIRY_MISSING: {
        value: 'USER_PAPER_STUDENT_CARD_EXPIRY_MISSING',
        text: 'Student card expiry date is required',
    },

    EXAM_RESULT_NOT_AVAILABLE: { value: 'EXAM_RESULT_NOT_AVAILABLE', text: 'Exam result not available' },

    ADMIN_LEAVE_REQUEST_NOT_PENDING: {
        value: 'ADMIN_LEAVE_REQUEST_NOT_PENDING',
        text: 'This request was already decided by someone else',
    },

    ABSENT_NOTICE_SHIFT_NOT_APPROVED: {
        value: 'ABSENT_NOTICE_SHIFT_NOT_APPROVED',
        text: 'The employee is no longer on this shift, so the request cannot be refused',
    },

    WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED: {
        value: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED',
        text: 'The check-in data has changed since this request was submitted',
    },
    WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER: {
        value: 'WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER',
        text: 'Applying this request would put the check-out before the check-in. Please reject it and submit a new one',
    },
    WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE: {
        value: 'WORK_HISTORY_EDIT_REQUEST_PENDING_CHECK_OUT_CHANGE',
        text: 'This check-in has a pending edit request that changes its check-out time. Approve or reject it first',
    },
    WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING: {
        value: 'WORK_HISTORY_EDIT_REQUEST_ALREADY_PENDING',
        text: 'This check-in already has another request pending',
    },
    WORK_HISTORY_EDIT_REQUEST_NOT_PENDING: {
        value: 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING',
        text: 'This request was already decided by someone else',
    },

    WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_INVALID_FORMAT',
        text: 'The uploaded file format does not match the expected export format',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR',
        text: 'One or more rows in the uploaded file could not be reconciled',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES',
        text: 'No changes were detected in the uploaded file',
    },

    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_MISSING: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_MISSING',
        text: 'Missing or invalid row identifier',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE',
        text: 'Duplicate row identifier',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND',
        text: 'No matching check-in record was found',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING',
        text: 'This row already has another request pending',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE',
        text: 'This row belongs to a different workplace than the rest of the file',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE',
        text: 'One of the values in this row is not a valid number',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN',
        text: 'The check-out time for this row would fall before the check-in time',
    },
    WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION: {
        value: 'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION',
        text: 'The gap between the check-in and check-out for this row is implausibly long',
    },

    RECONCILIATION_UNKNOWN_SOURCE: { value: 'RECONCILIATION_UNKNOWN_SOURCE', text: 'Unknown reconciliation source' },
    RECONCILIATION_INVALID_FORMAT: {
        value: 'RECONCILIATION_INVALID_FORMAT',
        text: 'The uploaded file does not match the selected source format',
    },
    RECONCILIATION_NO_PUNCH_ROWS: {
        value: 'RECONCILIATION_NO_PUNCH_ROWS',
        text: 'The uploaded file has no punch-log rows to reconcile',
    },
    RECONCILIATION_BATCH_NOT_DRAFT: {
        value: 'RECONCILIATION_BATCH_NOT_DRAFT',
        text: 'This reconciliation batch is already confirmed',
    },
    RECONCILIATION_BATCH_NOT_CONFIRMED: {
        value: 'RECONCILIATION_BATCH_NOT_CONFIRMED',
        text: 'This reconciliation batch is not confirmed yet',
    },
    RECONCILIATION_DECISIONS_INCOMPLETE: {
        value: 'RECONCILIATION_DECISIONS_INCOMPLETE',
        text: 'Some rows still need a mapping or field decision',
    },
    RECONCILIATION_DUPLICATE_CHECK_IN: {
        value: 'RECONCILIATION_DUPLICATE_CHECK_IN',
        text: 'Two or more rows are mapped to the same DGF check-in',
    },
    RECONCILIATION_STALE: {
        value: 'RECONCILIATION_STALE',
        text: 'DGF attendance has changed since this batch was confirmed',
    },
    RECONCILIATION_NOTHING_TO_EXPORT: {
        value: 'RECONCILIATION_NOTHING_TO_EXPORT',
        text: 'There are no accepted differences to export',
    },
});
