import { useGetActiveStressChecksQuery } from "../stress-check.api";
import { Skeleton } from "@/components/ui/skeleton";
import { StressCheckCard } from "./stress-check-card";
import { useTranslation } from "@/hooks/use-translation";
import UmbrellaIcon from "@/components/icons/umbrella-icon";
import HealthyRecognitionIcon from "@/components/icons/healthy-recognition-icon";
import { Button } from "@/components/ui/button";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";

const ActiveStressCheck = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const { data: responseData, isFetching } = useGetActiveStressChecksQuery();

  const stressChecks = responseData?.data ?? [];
  const hasStressChecks = stressChecks.length > 0;

  const goToSelfCare = () => {
    navigate({ to: RouteConfig.APP_SELF_CARE.route.to });
  };

  return (
    <div className="flex flex-col gap-6">
      <div className="flex gap-4 items-center">
        <span className="w-2 h-[22px] bg-primary-light" />
        <span className="font-bold text-[18px] leading-[22px]">
          {t("stressCheckList-staff.ongoingCheck")}
        </span>
      </div>
      <div className="px-6 rounded-[30px]">
        {isFetching ? (
          <div className="pb-3 min-h-60">
            <Skeleton className="aspect-360/228 w-full max-w-90 rounded-[30px]" />
          </div>
        ) : hasStressChecks ? (
          <div className="pb-3 min-h-60">
            <div className="flex gap-4">
              {stressChecks.map((stressCheck) => (
                <StressCheckCard
                  key={`sc-card-${stressCheck.id}`}
                  data={stressCheck}
                />
              ))}
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center">
            <div className="flex flex-col gap-4 items-center pt-8 pb-6">
              <UmbrellaIcon size={58} />
              <p className="font-bold text-[18px] leading-[22px] text-center">
                {t("stressCheckList-staff.noChecks")}
              </p>
            </div>
            <div className="bg-[#EBF8EE] rounded-2xl px-8 py-6 flex flex-col gap-4 items-center w-[800px]">
              <div className="flex flex-col gap-2 w-full">
                <div className="flex gap-2 items-center">
                  <HealthyRecognitionIcon size={24} className="text-yellow" />
                  <span className="font-bold text-sm leading-[17px]">
                    {t("stressCheckList-staff.anytimeSelfCheck")}
                  </span>
                </div>
                <p className="text-sm leading-5 whitespace-pre-line">
                  {t("stressCheckList-staff.selfCareInfo")}
                </p>
              </div>
              <Button
                variant="outline"
                className="w-[200px] border-2 border-primary-light bg-white rounded-[20px] px-4 py-2 h-auto"
                onClick={goToSelfCare}
              >
                <span className="font-bold text-sm leading-5">
                  {t("stressCheckList-staff.startSelfCare")}
                </span>
              </Button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default ActiveStressCheck;
