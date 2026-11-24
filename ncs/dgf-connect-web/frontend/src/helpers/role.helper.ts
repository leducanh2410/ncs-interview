import { Role } from '@/types/models/role';

const RoleHelper = {
    hasAnyPermission: (userPermissions: string[], permissions: string[]): boolean => {
        if (!permissions.length) {
            return true;
        }
        return userPermissions.some((permission) => permissions.includes(permission));
    },
    hasAnyRole: (userRoles: Role[], roles: string[]): boolean => {
        if (!roles.length) {
            return true;
        }
        return userRoles.some((role) => roles.includes(role.name));
    },
    /**
     * A route is accessible when the user satisfies its role requirement (if any)
     * AND its permission requirement (if any). Requirements that are not set are
     * treated as satisfied.
     */
    canAccessRoute: (
        auth: { permissions: string[]; roles: Role[] },
        route: { permissions?: string[]; roles?: string[] },
    ): boolean => {
        if (route.roles && !RoleHelper.hasAnyRole(auth.roles, route.roles)) {
            return false;
        }
        if (route.permissions && !RoleHelper.hasAnyPermission(auth.permissions, route.permissions)) {
            return false;
        }
        return true;
    },
};

export default RoleHelper;
