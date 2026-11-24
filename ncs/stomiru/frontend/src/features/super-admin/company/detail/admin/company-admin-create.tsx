import CompanyAdminForm from "./company-admin-form";

interface CompanyAdminCreateProps {
  companyId: number;
}

const CompanyAdminCreate = ({ companyId }: CompanyAdminCreateProps) => {
  return <CompanyAdminForm companyId={companyId} />;
};

export default CompanyAdminCreate;
