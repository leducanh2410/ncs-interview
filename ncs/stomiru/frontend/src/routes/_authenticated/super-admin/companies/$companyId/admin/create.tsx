import { createFileRoute } from "@tanstack/react-router";
import CompanyAdminCreate from "@/features/super-admin/company/detail/admin/company-admin-create";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/admin/create"
)({
  component: RouteComponent,
});

function RouteComponent() {
  const { companyId } = Route.useParams();

  return <CompanyAdminCreate companyId={Number(companyId)} />;
}
