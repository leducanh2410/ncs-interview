import HealthyRecognitionIcon from "@/components/icons/healthy-recognition-icon";
import DateTimeHelper from "@/helpers/date-time.helper";
import type { StressCheck } from "@/types/models/stress-check";

interface ReportHeaderProps {
  stressProject: StressCheck | null;
}

export const ReportHeader = ({ stressProject }: ReportHeaderProps) => {
  return (
    <div className="flex flex-col gap-4">
      <div className="flex gap-2 items-end">
        <HealthyRecognitionIcon size={32} className="text-primary-light" />
        <span className="flex items-end gap-1 font-bold">
          <span className="text-[24px] leading-[29px]">
            {stressProject?.year ?? "--"}
          </span>
          <span className="text-[18px] leading-[22px]">年度</span>
        </span>
      </div>
      <div className="flex flex-col gap-2.5">
        <span className="font-bold text-[16px] leading-[19px]">
          {stressProject?.start_date
            ? DateTimeHelper.format(stressProject?.start_date, "yyyy年 MM月 dd日")
            : ""}
          {" ～ "}
          {stressProject?.end_date
            ? DateTimeHelper.format(stressProject?.end_date, "yyyy年 MM月 dd日")
            : ""}
        </span>
        <span className="text-[14px] leading-[17px]">
          {stressProject?.title ?? ""}
        </span>
      </div>
    </div>
  );
};
