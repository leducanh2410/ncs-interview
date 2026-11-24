import { createFileRoute, redirect } from "@tanstack/react-router";
import SuperAdminLayout from "@/features/super-admin/super-admin-layout";
import RoleHelper from "@/helpers/role.helper";

export const Route = createFileRoute("/_authenticated/super-admin")({
  beforeLoad: ({ context }) => {
    const user = context.auth?.user;
    if (user && !RoleHelper.canViewSuperAdminPath(user.roles)) {
      throw redirect({ to: RoleHelper.getRedirectPath(user.roles) });
    }
  },
  component: SuperAdminLayout,
});
