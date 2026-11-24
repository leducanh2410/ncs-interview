import { createFileRoute, redirect } from "@tanstack/react-router";
import AdminLayout from "@/features/admin/admin-layout";
import RoleHelper from "@/helpers/role.helper";

export const Route = createFileRoute("/_authenticated/admin")({
  beforeLoad: ({ context }) => {
    const user = context.auth?.user;
    if (user && !RoleHelper.canViewAdminPath(user.roles)) {
      throw redirect({ to: RoleHelper.getRedirectPath(user.roles) });
    }
  },
  component: AdminLayout,
});
