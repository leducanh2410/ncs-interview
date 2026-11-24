import { createFileRoute } from "@tanstack/react-router";
import EmployeeList from "@/features/admin/employee/manage/employee-list";

export const Route = createFileRoute("/_authenticated/admin/employee/")({
  component: RouteComponent,
});

function RouteComponent() {
  const searchParams = { page_size: 10, ...Route.useSearch() };
  return <EmployeeList searchParams={searchParams} />;
}
