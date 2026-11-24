import { useTranslation } from "@/hooks/use-translation";

export function ChartLegend() {
  const { t } = useTranslation();

  return (
    <div className="inline-flex items-start justify-center gap-4 self-stretch">
      <span className="flex h-[22px] items-center gap-[6px] text-[14px] font-medium text-black">
        <span className="inline-block h-4 w-4 border border-[#EB253D] bg-[#EB253D1A]" />
        {t("stressCheckResultPage-staff.cautionZone")}
      </span>
      <span className="flex h-[22px] items-center gap-[6px] text-[14px] font-medium text-black">
        <span className="inline-block h-4 w-4 border border-[#00A429] bg-[#15CA4233]" />
        {t("stressCheckResultPage-staff.answerZone")}
      </span>
      <span className="flex h-[22px] items-center gap-[6px] text-[14px] font-medium text-black">
        <span className="inline-block h-4 w-4 border border-dashed border-black" />
        {t("common.previous")}
      </span>
    </div>
  );
}
