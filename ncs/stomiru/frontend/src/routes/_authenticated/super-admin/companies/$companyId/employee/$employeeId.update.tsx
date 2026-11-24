import EmployeeUpdate from '@/features/admin/employee/employee-update';
import { employeeQueryOptions } from '@/features/admin/employee/employee.api';
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/employee/$employeeId/update',
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
})

function RouteComponent() {
  const { companyId = 0, employeeId = 0 } = Route.useParams();
  return <EmployeeUpdate employeeId={Number(employeeId)} companyId={Number(companyId)} />
}