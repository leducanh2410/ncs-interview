import { createFileRoute } from "@tanstack/react-router";
import EmployeeUpdate from "@/features/admin/employee/employee-update";
import { employeeQueryOptions } from "@/features/admin/employee/employee.api";

export const Route = createFileRoute(
  "/_authenticated/admin/employee/$employeeId/update"
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
  const { employeeId = 0 } = Route.useParams();
  return <EmployeeUpdate employeeId={Number(employeeId)} />
}