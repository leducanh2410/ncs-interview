import { createFileRoute } from "@tanstack/react-router";
import SuperAdminLogin from "@/features/auth/super-admin-login";

export const Route = createFileRoute("/(auth)/auth/super-admin")({
  component: SuperAdminLogin,
});
