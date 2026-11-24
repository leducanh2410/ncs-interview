import EmployeeCreate from '@/features/admin/employee/employee-create'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/employee/create',
)({
  component: RouteComponent,
})

function RouteComponent() {
  const { companyId } = Route.useParams();
  return <EmployeeCreate companyId={Number(companyId)} />
}
