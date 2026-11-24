import { createFileRoute } from "@tanstack/react-router";
import AdminLogin from "@/features/auth/admin";

export const Route = createFileRoute("/(auth)/auth/admin")({
  component: AdminLogin,
});
