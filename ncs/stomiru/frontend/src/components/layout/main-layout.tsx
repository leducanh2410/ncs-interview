import { Navigate, Outlet } from "@tanstack/react-router";
import { useAtomValue } from "jotai";
import { authAtom } from "@/global.state";
import RouteConfig from "@/configs/route.config";

const MainLayout = () => {
  const auth = useAtomValue(authAtom);

  if (!auth) {
    return <Navigate to={RouteConfig.LOGIN.route.to} />;
  }

  return (
    <div className="flex flex-col relative isolate">
      <div className="h-screen relative flex flex-col">
        <Outlet />
      </div>
    </div>
  );
};

export default MainLayout;
