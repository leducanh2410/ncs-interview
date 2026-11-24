import { Navigate, Outlet } from "@tanstack/react-router";
import { useAtomValue } from "jotai";
import { authUserAtom } from "@/global.state";
import RouteConfig from "@/configs/route.config";

export const AuthLayout = () => {
  const user = useAtomValue(authUserAtom);

  if (user) {
    return <Navigate to={RouteConfig.HOME.route.to} />;
  }

  return <Outlet />;
};
