import EmployeeDetail from '@/features/admin/employee/detail/employee-detail';
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/employee/$employeeId/',
)({
  component: RouteComponent,
})

function RouteComponent() {
  const { companyId, employeeId } = Route.useParams();

  return (
    <EmployeeDetail
      employeeId={Number(employeeId)}
      companyId={Number(companyId)}
      shortcut={true}
    />
  );
}
