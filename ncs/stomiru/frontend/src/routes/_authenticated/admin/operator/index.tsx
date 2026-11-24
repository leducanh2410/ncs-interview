import { createFileRoute } from "@tanstack/react-router";
import OperatorList from "@/features/admin/operator/manage/operator-list";

export const Route = createFileRoute("/_authenticated/admin/operator/")({
  component: OperatorList,
});
