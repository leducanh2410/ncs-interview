import StressProjectReport from '@/features/admin/stress-project/report/stress-project-report';
import { staffManagerStressProjectQueryOptions, staffManagerstressProjectReportQueryOptions } from '@/features/app/stress-check/stress-check.api';
import { authUserAtom } from '@/global.state';
import RoleHelper from '@/helpers/role.helper';
import { useSuspenseQuery } from '@tanstack/react-query';
import { createFileRoute } from '@tanstack/react-router'
import { useAtomValue } from 'jotai';

export const Route = createFileRoute(
  '/_authenticated/app/stress-check/$stressCheckId/report',
)({
  component: RouteComponent,
})

function RouteComponent() {
  const { stressCheckId = 0 } = Route.useParams();

  const authUser = useAtomValue(authUserAtom);
  const isStaffManager = RoleHelper.isStaffManager(authUser?.roles || []);

  if (!isStaffManager) {
    return null;
  }

  const { data: stressProjectData } = useSuspenseQuery(
    staffManagerStressProjectQueryOptions(Number(stressCheckId))
  );
  const { data: reportApiData } = useSuspenseQuery(
    staffManagerstressProjectReportQueryOptions(Number(stressCheckId))
  );

  return <StressProjectReport stressProjectData={stressProjectData} reportApiData={reportApiData} />
}
