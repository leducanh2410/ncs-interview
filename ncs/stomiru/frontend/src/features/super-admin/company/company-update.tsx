import { useSuspenseQuery } from "@tanstack/react-query";
import type { Company } from "@/types/models/company";
import { companyQueryOptions } from "./company.api";
import { CompanyForm } from "./form/company-form";
import { Route } from "@/routes/_authenticated/super-admin/companies/$companyId/update";

export const CompanyUpdate = () => {
  const { companyId = 0 } = Route.useParams();
  const { data: company } = useSuspenseQuery(
    companyQueryOptions(Number(companyId))
  );

  return <CompanyForm initialValues={{ ...(company.data as Company) }} />;
};
