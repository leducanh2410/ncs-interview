import { createFileRoute } from "@tanstack/react-router";
import StressProjectDetail from "@/features/admin/stress-project/detail/stress-project-detail";
import { stressProjectQueryOptions } from "@/features/admin/stress-project/stress-project.api";

export const Route = createFileRoute(
  "/_authenticated/admin/stress-project/$stressProjectId/"
)({
  loader: async ({ context: { queryClient }, params: { stressProjectId } }) => {
    const data = await queryClient.ensureQueryData(
      stressProjectQueryOptions(Number(stressProjectId))
    );

    return {
      data,
    };
  },
  component: StressProjectDetail,
});
