import { createFileRoute } from "@tanstack/react-router";
import EmployeeDetail from "@/features/admin/employee/detail/employee-detail";
import { employeeQueryOptions } from "@/features/admin/employee/employee.api";

export const Route = createFileRoute(
  "/_authenticated/admin/employee/$employeeId/"
)({
  loader: async ({ context: { queryClient }, params: { employeeId } }) => {
    const data = await queryClient.ensureQueryData(
      employeeQueryOptions(Number(employeeId))
    );

    return {
      data,
    };
  },
  component: RouteComponent,
});

function RouteComponent() {
  const { employeeId } = Route.useParams();

  return <EmployeeDetail employeeId={Number(employeeId)} />
}