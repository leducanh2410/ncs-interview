import { createFileRoute, useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import { ApiError } from "@/helpers/api.helper";
import BackButton from "@/components/custom/back-button";
import StressCheckExam from "@/features/app/stress-check/exam/stress-check-exam";
import { stressCheckExamQueryOptions } from "@/features/app/stress-check/stress-check.api";
import { useTranslation } from "@/hooks/use-translation";

function StressCheckErrorComponent({ error }: { error: unknown }) {
  const { t } = useTranslation();
  const navigate = useNavigate();

  if (error instanceof ApiError && error.status === 403) {
    return (
      <div className="mt-4 max-w-[992px] px-4 w-full mx-auto flex flex-col gap-4 pb-25">
        <div className="py-10 pb-2">
          <BackButton
            onClick={() =>
              void navigate({ to: RouteConfig.APP_STRESS_CHECK.path })
            }
            label={t("stressCheckPage-staff.returnHome")}
          />
        </div>
        <div className="px-6 flex flex-col gap-6">
          <div className="pt-10 pb-8 flex flex-col gap-4 text-center">
            <div className="font-bold text-[24px] leading-[34px] whitespace-pre-wrap">
              {t("stressCheckPage-staff.notAvailable")}
            </div>
          </div>
        </div>
      </div>
    );
  }
}

export const Route = createFileRoute(
  "/_authenticated/app/stress-check/$stressCheckId/exam"
)({
  loader: async ({ context: { queryClient }, params: { stressCheckId } }) => {
    const data = await queryClient.ensureQueryData(
      stressCheckExamQueryOptions(Number(stressCheckId))
    );

    return {
      data,
    };
  },
  errorComponent: StressCheckErrorComponent,
  component: StressCheckExam,
});
