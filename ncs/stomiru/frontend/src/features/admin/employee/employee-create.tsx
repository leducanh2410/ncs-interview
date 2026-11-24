import EmployeeForm from "./form/employee-form";

interface EmployeeCreateProps {
  companyId?: number;
}

const EmployeeCreate = ({ companyId }: EmployeeCreateProps) => {
  return <EmployeeForm companyId={companyId} />;
};

export default EmployeeCreate;
