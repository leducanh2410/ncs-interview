import { createFileRoute } from "@tanstack/react-router";
import { StressProjectCreate } from "@/features/admin/stress-project/stress-project-create";

export const Route = createFileRoute(
  "/_authenticated/admin/stress-project/create"
)({
  component: StressProjectCreate,
});
