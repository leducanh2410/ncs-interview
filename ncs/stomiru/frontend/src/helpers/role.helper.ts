import type { Role } from "@/types/models/role";
import AuthorityEnum, { AdminAuthorityEnum } from "@/enums/authority.enum";
import RoleEnum from "@/enums/role.enum";
import EnumHelper from "./enum.helper";

const RoleHelper = {
  appLoginPath: "/auth/login",
  adminLoginPath: "/auth/admin",
  getRedirectPath: (roles: Role[]) => {
    return RoleHelper.getRedirectPathByRole(roles?.[0]?.name || "");
  },
  getRedirectPathByRole: (role: string) => {
    switch (role) {
      case RoleEnum.SuperAdmin.value:
        return "/super-admin/companies";

      case RoleEnum.Admin.value:
      case RoleEnum.Doctor.value:
        return "/admin/dashboard";

      case RoleEnum.Staff.value:
      case RoleEnum.StaffManager.value:
        return "/app/stress-check";

      default:
        return "/auth/login";
    }
  },
  getAdminAuthorityLabel: (roles: Role[]) => {
    if (!roles || roles.length === 0) {
      return "-";
    }

    const role = roles[0].name;
    return EnumHelper.getText(AdminAuthorityEnum, role) || "-";
  },
  getAuthorityLabel: (roles: Role[]) => {
    if (!roles || roles.length === 0) {
      return "-";
    }

    const role = roles[0].name;
    return EnumHelper.getText(AuthorityEnum, role) || "-";
  },
  isSuperAdmin: (roles: Role[]) => {
    return roles.some((role) => role.name === RoleEnum.SuperAdmin.value);
  },
  isDoctor: (roles: Role[]) => {
    return roles.some((role) => role.name === RoleEnum.Doctor.value);
  },
  isStaffManager: (roles: Role[]) => {
    return roles.some((role) => role.name === RoleEnum.StaffManager.value);
  },
  canViewAdminPath: (roles: Role[] = []) => {
    return roles.some((role) => role.name === RoleEnum.Admin.value || role.name === RoleEnum.Doctor.value);
  },
  canViewSuperAdminPath: (roles: Role[] = []) => {
    return roles.some((role) => role.name === RoleEnum.SuperAdmin.value);
  },
  canViewAppPath: (roles: Role[] = []) => {
    return roles.some((role) => role.name === RoleEnum.Staff.value || role.name === RoleEnum.StaffManager.value);
  },
};

export default RoleHelper;
