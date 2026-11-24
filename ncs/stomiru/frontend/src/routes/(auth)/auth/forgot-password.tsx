import { createFileRoute } from "@tanstack/react-router";
import ForgotPassword from "@/features/auth/admin/forgot-password";

export const Route = createFileRoute(
  "/(auth)/auth/forgot-password"
)({
  component: ForgotPassword,
});
