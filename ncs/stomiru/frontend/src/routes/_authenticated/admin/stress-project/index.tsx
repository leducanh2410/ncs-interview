import { createFileRoute } from "@tanstack/react-router";
import StressProjectList from "@/features/admin/stress-project/manage/stress-project-list";

export const Route = createFileRoute("/_authenticated/admin/stress-project/")({
  component: StressProjectList,
});
