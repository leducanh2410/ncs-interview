import { createFileRoute } from "@tanstack/react-router";
import OperatorUpdate from "@/features/admin/operator/operator-update";
import { operatorQueryOptions } from "@/features/admin/operator/operator.api";

export const Route = createFileRoute(
  "/_authenticated/admin/operator/$operatorId/update"
)({
  loader: async ({ context: { queryClient }, params: { operatorId } }) => {
    const data = await queryClient.ensureQueryData(
      operatorQueryOptions(Number(operatorId))
    );

    return {
      data,
    };
  },
  component: OperatorUpdate,
});
