import { useNavigate } from "@tanstack/react-router";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import ResponseStatusEnum from "@/enums/response-status.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import { ResponseStatusBadge } from "@/components/custom/m-badge";
import HealthyRecognitionIcon from "@/components/icons/healthy-recognition-icon";
import SuccessIcon from "@/components/icons/success-icon";
import { Button } from "@/components/ui/button";
import { useTranslation } from "@/hooks/use-translation";

interface StressCheckCardProps {
  data: StressCheck;
}

const getResponseStatus = (data: StressCheck): string => {
  const session = data.exam_sessions?.[0];
  if (session?.status === "submitted") {
    return ResponseStatusEnum.Answered.value;
  }
  if (session?.status === "in_progress") {
    return ResponseStatusEnum.Answering.value;
  }
  return ResponseStatusEnum.Unanswered.value;
};

export const StressCheckCard = ({ data }: StressCheckCardProps) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const isAnswered =
    getResponseStatus(data) === ResponseStatusEnum.Answered.value;
  const isAnswering =
    getResponseStatus(data) === ResponseStatusEnum.Answering.value;
  const cardDateFormat = t("stressCheckList-staff.cardDateFormat");

  const handleClick = () => {
    const to = isAnswered
      ? CommonHelper.replaceUrlParam(RouteConfig.APP_STRESS_CHECK_RESULT.path, {
          stressCheckId: data.id,
        })
      : CommonHelper.replaceUrlParam(RouteConfig.APP_STRESS_CHECK_EXAM.path, {
          stressCheckId: data.id,
        });
    navigate({ to });
  };

  return (
    <div className="rounded-[30px] border border-[#BBC5C8] max-w-90 min-h-[228px] w-full overflow-hidden">
      <div className={`h-20 flex items-center justify-between px-6 ${isAnswered ? "bg-[linear-gradient(104.26deg,#434343_4.7%,#666666_77.77%)]" : "bg-linear"}`}>
        <div className="flex gap-2 items-end">
          {isAnswered ? <SuccessIcon size={32} className="text-yellow" /> : <HealthyRecognitionIcon size={32} className="text-yellow" />}
          <span className={`font-bold text-[24px] leading-[29px] ${isAnswered ? "text-white" : "text-primary-foreground"}`}>
            {data.year}
          </span>
          <span className={`font-bold text-[18px] leading-[22px] ${isAnswered ? "text-white" : "text-primary-foreground"}`}>
            {t("stressCheckList-staff.year")}
          </span>
        </div>
        <div className="flex">
          <ResponseStatusBadge status={getResponseStatus(data)} />
        </div>
      </div>
      <div className="p-6 flex flex-col gap-2.5">
        <div className="pb-2 flex flex-col gap-2.5">
          <div className="font-bold text-[16px] leading-[19px] whitespace-normal break-words">
            {data.title}
          </div>
          <div className="text-[14px] leading-[17px]">
            {DateTimeHelper.format(data.start_date, cardDateFormat)}
            {" 〜 "}
            {DateTimeHelper.format(data.end_date, cardDateFormat)}
          </div>
        </div>
        <div>
          <Button
            variant={isAnswered ? "outline" : "default"}
            className={`w-full ${isAnswered ? "bg-white border-2 border-primary" : ""}`}
            onClick={handleClick}
          >
            <span>
              {isAnswered
                ? t("stressCheckList-staff.checkResults")
                : isAnswering
                  ? t("stressCheckPage-staff.continueAnswering")
                  : t("stressCheckList-staff.startAnswering")}
            </span>
          </Button>
        </div>
      </div>
    </div>
  );
};
