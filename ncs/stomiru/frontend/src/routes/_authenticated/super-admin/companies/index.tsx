import { createFileRoute } from "@tanstack/react-router";
import { CompanyList } from "@/features/super-admin/company/manage/company-list";

export const Route = createFileRoute("/_authenticated/super-admin/companies/")({
  component: CompanyList,
});
