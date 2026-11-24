import CompanyAdminForm from "./company-admin-form";
import { useSuspenseQuery } from "@tanstack/react-query";
import { operatorQueryOptions, type Operator } from "@/features/admin/operator/operator.api";

interface CompanyAdminUpdateProps {
  companyId: number;
  adminId: number;
}

const CompanyAdminUpdate = ({ companyId, adminId }: CompanyAdminUpdateProps) => {
  const { data: companyAdmin } = useSuspenseQuery(
    operatorQueryOptions(Number(adminId))
  );

  return <CompanyAdminForm companyId={companyId} initialValues={{ ...(companyAdmin.data as Operator) }} />;
};

export default CompanyAdminUpdate;
