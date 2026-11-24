export type RoleName = 'GLOBAL_ADMIN' | 'ADMIN' | 'USER';

export const RoleNameEnum: { [key in RoleName]: { value: string; text: string } } = Object.freeze({
    GLOBAL_ADMIN: { value: 'global_admin', text: 'Global Admin' },
    ADMIN: { value: 'admin', text: 'Admin' },
    USER: { value: 'user', text: 'User' },
});

export const RoleIdNumberEnum: { [key in RoleName]: number } = Object.freeze({
    GLOBAL_ADMIN: 1,
    ADMIN: 10,
    USER: 100,
});

export const getManagerRoles = (): string[] => [RoleNameEnum.GLOBAL_ADMIN.value, RoleNameEnum.ADMIN.value];
