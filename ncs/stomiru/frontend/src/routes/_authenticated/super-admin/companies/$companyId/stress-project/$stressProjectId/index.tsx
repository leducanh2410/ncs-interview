import { createFileRoute } from "@tanstack/react-router";
import { stressProjectQueryOptions } from "@/features/admin/stress-project/stress-project.api";
import { StressProjectDetail } from "@/features/super-admin/company/detail/stress-project-detail";

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/"
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
