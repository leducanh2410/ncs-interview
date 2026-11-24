import { createFileRoute } from "@tanstack/react-router";
import { z } from "zod";
import { StressProjectUpdate } from "@/features/admin/stress-project/stress-project-update";
import { stressProjectQueryOptions } from "@/features/admin/stress-project/stress-project.api";

const searchSchema = z.object({
  continue: z.any().optional(),
});

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/update"
)({
  validateSearch: searchSchema,
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
  const { companyId, stressProjectId } = Route.useParams();
  const { continue: continueUrl } = Route.useSearch();

  return (
    <StressProjectUpdate
      continueUrl={continueUrl}
      companyId={Number(companyId)}
      stressProjectId={Number(stressProjectId)}
    />
  );
}
