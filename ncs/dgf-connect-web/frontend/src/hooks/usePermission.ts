import { useAtom } from 'jotai';
import { PermissionName, PermissionNameEnum } from '@/enums/permission_name.enum';
import { authAtom } from '@/pages/global.state';

export const useAuthLoaded = (): boolean => {
    const [auth] = useAtom(authAtom);

    return auth !== null;
};

export const usePermissions = <T extends PermissionName>(permissions: T[]) => {
    const [auth] = useAtom(authAtom);
    const userPermissions = auth?.permissions || [];

    return permissions.reduce(
        (acc, perm) => {
            acc[perm] = userPermissions.includes(PermissionNameEnum[perm].value);
            return acc;
        },
        {} as Record<T, boolean>,
    );
};
