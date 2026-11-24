import EmployeeList from '@/features/admin/employee/manage/employee-list'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/employee/',
)({
  component: RouteComponent,
})

function RouteComponent() {
  const { companyId = 0 } = Route.useParams();
  const searchParams = { page_size: 10, ...Route.useSearch() };
  return <EmployeeList searchParams={{ ...searchParams, company_id: companyId }} shortCut={true} companyId={Number(companyId)} />
}
