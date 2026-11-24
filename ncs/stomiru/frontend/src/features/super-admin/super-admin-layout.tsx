import { Outlet } from "@tanstack/react-router";
import { SuperAdminLoginTopbar } from "./super-admin-topbar";

const SuperAdminLayout = () => {
  return (
    <div className="flex-1 flex flex-col gap-6">
      <SuperAdminLoginTopbar />
      <Outlet />
    </div>
  );
};

export default SuperAdminLayout;
