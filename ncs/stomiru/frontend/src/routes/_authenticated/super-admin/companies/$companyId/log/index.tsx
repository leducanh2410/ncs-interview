import { createFileRoute } from "@tanstack/react-router";
import LogList from "@/features/super-admin/log/manage/log-list";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/log/"
)({
  component: LogList,
});
