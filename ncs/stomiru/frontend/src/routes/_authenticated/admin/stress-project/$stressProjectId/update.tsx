import { createFileRoute } from "@tanstack/react-router";
import { StressProjectUpdate } from "@/features/admin/stress-project/stress-project-update";
import { stressProjectQueryOptions } from "@/features/admin/stress-project/stress-project.api";

export const Route = createFileRoute(
  "/_authenticated/admin/stress-project/$stressProjectId/update"
)({
  loader: async ({ context: { queryClient }, params: { stressProjectId } }) => {
    const data = await queryClient.ensureQueryData(
      stressProjectQueryOptions(Number(stressProjectId))
    );

    return {
      data,
    };
  },
  component: RouteComponent,
});

function RouteComponent() {
  const { stressProjectId } = Route.useParams();

  return <StressProjectUpdate stressProjectId={Number(stressProjectId)} />;
}
