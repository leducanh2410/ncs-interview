import { createFileRoute } from "@tanstack/react-router";
import { z } from "zod";
import { StressProjectCreate } from "@/features/admin/stress-project/stress-project-create";

const searchSchema = z.object({
  continue: z.any().optional(),
});

export const Route = createFileRoute(
  "/_authenticated/super-admin/companies/$companyId/stress-project/create"
)({
  validateSearch: searchSchema,
  component: RouteComponent,
});

function RouteComponent() {
  const { companyId } = Route.useParams();
  const { continue: continueUrl } = Route.useSearch();

  return (
    <StressProjectCreate
      companyId={Number(companyId)}
      continueUrl={continueUrl}
    />
  );
}
