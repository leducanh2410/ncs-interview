import { createFileRoute, redirect } from "@tanstack/react-router";
import AppLayout from "@/features/app/app-layout";
import RoleHelper from "@/helpers/role.helper";

export const Route = createFileRoute("/_authenticated/app")({
  beforeLoad: ({ context }) => {
    const user = context.auth?.user;
    if (user && !RoleHelper.canViewAppPath(user.roles)) {
      throw redirect({ to: RoleHelper.getRedirectPath(user.roles) });
    }
  },
  component: AppLayout,
});
