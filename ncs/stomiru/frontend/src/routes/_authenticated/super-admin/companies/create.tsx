import { createFileRoute } from "@tanstack/react-router";
import { CompanyCreate } from "@/features/super-admin/company/company-create";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/create"
)({
  component: CompanyCreate,
});
