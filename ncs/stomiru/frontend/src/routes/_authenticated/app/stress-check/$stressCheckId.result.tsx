import { createFileRoute } from "@tanstack/react-router";
import { stressCheckResultQueryOptions } from "@/features/app/stress-check/stress-check.api";
import StressCheckResult from "@/features/app/stress-check/result/stress-check-result";
import { useSuspenseQuery } from "@tanstack/react-query";

export const Route = createFileRoute(
  "/_authenticated/app/stress-check/$stressCheckId/result"
)({
  loader: ({ context: { queryClient }, params: { stressCheckId } }) =>
    queryClient.ensureQueryData(
      stressCheckResultQueryOptions(Number(stressCheckId))
    ),
  component: RouteComponent,
});

function RouteComponent() {
  const { stressCheckId = 0 } = Route.useParams();
  const { data: resultResponse } = useSuspenseQuery(
    stressCheckResultQueryOptions(Number(stressCheckId))
  );
  return <StressCheckResult data={resultResponse.data ?? null} id={stressCheckId} />
}
