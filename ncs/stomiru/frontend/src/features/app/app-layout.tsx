import { Outlet } from "@tanstack/react-router";
import { AppTopbar } from "./app-topbar";

const AppLayout = () => {
  return (
    <div className="flex-1 flex flex-col">
      <AppTopbar />
      <Outlet />
    </div>
  );
};

export default AppLayout;
