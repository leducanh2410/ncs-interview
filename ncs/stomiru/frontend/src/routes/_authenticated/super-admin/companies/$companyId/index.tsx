import { createFileRoute } from "@tanstack/react-router";
import { CompanyDetail } from "@/features/super-admin/company/detail/company-detail";
import { companyQueryOptions } from "@/features/super-admin/company/company.api";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/"
)({
  loader: async ({ context: { queryClient }, params: { companyId } }) => {
    const data = await queryClient.ensureQueryData(
      companyQueryOptions(Number(companyId))
    );

    return {
      data,
    };
  },
  component: CompanyDetail,
});
