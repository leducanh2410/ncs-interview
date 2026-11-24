import { Outlet } from "@tanstack/react-router";
import { AdminTopbar } from "./admin-topbar";

const AdminLayout = () => {
  return (
    <div className="flex-1 flex flex-col gap-6">
      <AdminTopbar />
      <Outlet />
    </div>
  );
};

export default AdminLayout;
