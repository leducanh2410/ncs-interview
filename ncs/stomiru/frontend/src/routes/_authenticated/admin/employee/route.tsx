import { createFileRoute, Outlet, redirect } from '@tanstack/react-router'
import RoleHelper from '@/helpers/role.helper';
import RouteConfig from '@/configs/route.config';

export const Route = createFileRoute('/_authenticated/admin/employee')({
    beforeLoad: ({ context }) => {
    const user = context.auth?.user;
    if (user && RoleHelper.isDoctor(user.roles || [])) {
      throw redirect({ to: RouteConfig.ADMIN_DASHBOARD.path });
    }
  },
  component: Outlet,
})
