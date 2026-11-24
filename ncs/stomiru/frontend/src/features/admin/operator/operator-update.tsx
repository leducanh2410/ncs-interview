import { useSuspenseQuery } from "@tanstack/react-query";
import OperatorForm from "./form/operator-form";
import { type Operator, operatorQueryOptions } from "./operator.api";
import { Route } from "@/routes/_authenticated/admin/operator/$operatorId.update";

const OperatorUpdate = () => {
  const { operatorId = 0 } = Route.useParams();
  const { data: employeeData } = useSuspenseQuery(
    operatorQueryOptions(Number(operatorId))
  );

  return (
    <OperatorForm initialValues={{ ...(employeeData.data as Operator) }} />
  );
};

export default OperatorUpdate;
