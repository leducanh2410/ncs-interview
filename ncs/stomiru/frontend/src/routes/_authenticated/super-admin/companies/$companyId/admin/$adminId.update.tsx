import CompanyAdminUpdate from '@/features/super-admin/company/detail/admin/company-admin-update'
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/admin/$adminId/update',
)({
  component: RouteComponent,
})

function RouteComponent() {
const { companyId, adminId } = Route.useParams();
  return <CompanyAdminUpdate companyId={companyId} adminId={adminId} />
}
