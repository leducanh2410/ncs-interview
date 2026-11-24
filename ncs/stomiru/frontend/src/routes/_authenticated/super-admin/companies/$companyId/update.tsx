import { createFileRoute } from "@tanstack/react-router";
import { CompanyUpdate } from "@/features/super-admin/company/company-update";
import { companyQueryOptions } from "@/features/super-admin/company/company.api";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/update"
)({
  loader: async ({ context: { queryClient }, params: { companyId } }) => {
    const data = await queryClient.ensureQueryData(
      companyQueryOptions(Number(companyId))
    );

    return {
      data,
    };
  },
  component: CompanyUpdate,
});
