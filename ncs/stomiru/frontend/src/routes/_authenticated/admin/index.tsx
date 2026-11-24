import { Navigate, createFileRoute } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";

export const Route = createFileRoute("/_authenticated/admin/")({
  component: RouteComponent,
});

function RouteComponent() {
  return (
    <Navigate to={RouteConfig.SUPER_ADMIN_COMPANY_ADMIN_CREATE.route.to} />
  );
}
