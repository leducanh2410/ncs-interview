import { createFileRoute } from "@tanstack/react-router";
import EmployeeCreate from "@/features/admin/employee/employee-create";

export const Route = createFileRoute("/_authenticated/admin/employee/create")({
  component: EmployeeCreate,
});
