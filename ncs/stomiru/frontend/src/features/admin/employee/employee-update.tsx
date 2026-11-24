import { useSuspenseQuery } from "@tanstack/react-query";
import type { Employee } from "@/types/models/user";
import { employeeQueryOptions } from "./employee.api";
import EmployeeForm from "./form/employee-form";

interface EmployeeUpdateProps {
  employeeId: number
  companyId?: number;
}

const EmployeeUpdate = ({ employeeId, companyId }: EmployeeUpdateProps) => {
  const { data: employeeData } = useSuspenseQuery(
    employeeQueryOptions(Number(employeeId))
  );

  return (
    <EmployeeForm initialValues={{ ...(employeeData.data as Employee) }} companyId={companyId} />
  );
};

export default EmployeeUpdate;
