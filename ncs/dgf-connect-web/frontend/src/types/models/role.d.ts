export interface Role {
    id: number;
    name: string;
    display_name: string | null;
    description: string | null;
}

export interface Permission {
    id: number;
    name: string;
}

export interface RoleWithPermissions extends Role {
    permissions: Permission[];
}
