import { PermissionNameEnum } from '@/enums/permission_name.enum';

/**
 * Narrows the filter and export picker to admins who can currently sign in and
 * check themselves in. Says nothing about the rows in the table: those come
 * straight from `admin_attendance_session`, so a revoked or disabled admin
 * keeps their past attendance — they just can no longer be picked here.
 */
export const ATTENDANCE_ADMIN_PERMISSIONS = [
    PermissionNameEnum.MANAGER_AUTH.value,
    PermissionNameEnum.MANAGER_ATTENDANCE_CHECK_IN.value,
];
