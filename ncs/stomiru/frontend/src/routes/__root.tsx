import type { QueryClient } from "@tanstack/react-query";
import { Outlet, createRootRouteWithContext } from "@tanstack/react-router";
import type { AuthData } from "@/features/auth/auth.api";

export const Route = createRootRouteWithContext<{
  queryClient: QueryClient;
  auth?: AuthData | null;
}>()({
  component: Outlet,
});
