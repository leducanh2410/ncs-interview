import ExamineeList from '@/features/admin/examinee/manage/examinee-list';
import { createFileRoute } from '@tanstack/react-router'

export const Route = createFileRoute(
  '/_authenticated/super-admin/companies/$companyId/stress-project/$stressProjectId/examinee/',
)({
  component: RouteComponent,
})

function RouteComponent() {
  const { stressProjectId = 0 } = Route.useParams();
  const searchParams = { page_size: 10, ...Route.useSearch() };

  return <ExamineeList searchParams={searchParams} stressProjectId={Number(stressProjectId)} shortCut />
}
