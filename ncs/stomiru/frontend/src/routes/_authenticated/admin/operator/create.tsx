import { createFileRoute } from "@tanstack/react-router";
import OperatorCreate from "@/features/admin/operator/operator-create";

export const Route = createFileRoute("/_authenticated/admin/operator/create")({
  component: OperatorCreate,
});
