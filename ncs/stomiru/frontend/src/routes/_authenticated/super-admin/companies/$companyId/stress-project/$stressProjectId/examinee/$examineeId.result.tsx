import { examineeResultQueryOptions } from '@/features/admin/examinee/examinee.api'
import StressCheckResult from '@/features/app/stress-check/result/stress-check-result'
import { useSuspenseQuery } from '@tanstack/react-query';
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/examinee/$examineeId/result',
)({
  loader: ({ context: { queryClient }, params: { stressProjectId, examineeId } }) =>
    queryClient.ensureQueryData(
      examineeResultQueryOptions(Number(stressProjectId), Number(examineeId))
    ),
  component: RouteComponent,
})

function RouteComponent() {
  const { stressProjectId = 0, examineeId = 0 } = Route.useParams();
  const { data: resultResponse } = useSuspenseQuery(
    examineeResultQueryOptions(Number(stressProjectId), Number(examineeId))
  );

  return (
    <StressCheckResult shortcut={true} data={resultResponse.data ?? null} id={examineeId} />
  )
}
