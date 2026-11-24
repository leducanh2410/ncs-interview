import { useSuspenseQuery } from "@tanstack/react-query";
import { createFileRoute } from "@tanstack/react-router";
import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import StressProjectReport from "@/features/admin/stress-project/report/stress-project-report";
import {
  stressProjectQueryOptions,
  stressProjectReportQueryOptions,
} from "@/features/admin/stress-project/stress-project.api";
import { useTranslation } from "@/hooks/use-translation";

export const Route = createFileRoute(
  "/_authenticated/admin/stress-project/$stressProjectId/report"
)({
  loader: async ({ context: { queryClient }, params: { stressProjectId } }) => {
    const data = await queryClient.ensureQueryData(
      stressProjectQueryOptions(Number(stressProjectId))
    );

    return { data };
  },
  component: RouteComponent,
});

function RouteComponent() {
  const { stressProjectId = 0 } = Route.useParams();
  const { t } = useTranslation();

  const { data: stressProjectData } = useSuspenseQuery(
    stressProjectQueryOptions(Number(stressProjectId))
  );

  const { data: reportApiData } = useSuspenseQuery(
    stressProjectReportQueryOptions(Number(stressProjectId))
  );

  if (stressProjectData.data?.status !== StressCheckStatusEnum.Finished.value) {
    return (
      <div className="flex items-center justify-center">
        <p>{t("adminDashboard.reportNotReady")}</p>
      </div>
    );
  }

  return (
    <StressProjectReport
      stressProjectData={stressProjectData}
      reportApiData={reportApiData}
    />
  );
}
