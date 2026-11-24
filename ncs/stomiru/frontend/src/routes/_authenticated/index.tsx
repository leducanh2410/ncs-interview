import { createFileRoute, redirect } from "@tanstack/react-router";
import RoleHelper from "@/helpers/role.helper";

export const Route = createFileRoute("/_authenticated/")({
  beforeLoad: ({ context }) => {
    const user = context.auth?.user;
    if (!user) {
      throw redirect({ to: "/auth/login" });
    }

    throw redirect({ to: RoleHelper.getRedirectPath(user.roles) });
  },
});
