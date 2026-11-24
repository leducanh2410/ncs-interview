import StringHelper from "@/helpers/string.helper";
import { useTranslation } from "@/hooks/use-translation";
import type { ScaleItem } from "./types";

export function ScoreTable({ items }: { items: ScaleItem[] }) {
  const { t } = useTranslation();

  return (
    <div className="flex w-full flex-col">
      <div className="inline-flex h-10 w-full items-center rounded-tl-[8px] rounded-tr-[8px] border-b border-[#EBEBF5] bg-[#F8F8FC] px-2">
        <div className="flex h-10 flex-1 items-center gap-2.5 px-3 py-1">
          <p className="text-[14px] font-bold leading-normal text-black">
            {t("stressCheckResultPage-staff.item")}
          </p>
        </div>
        <div className="flex h-10 w-[90px] items-center gap-2.5 px-3 py-1">
          <p className="text-[14px] font-bold leading-normal text-black">
            {t("stressCheckResultPage-staff.score")}
          </p>
        </div>
        <div className="flex h-10 w-[90px] items-center gap-2.5 px-3 py-1">
          <p className="text-[14px] font-bold leading-normal text-black">
            {t("common.previous")}
          </p>
        </div>
      </div>

      {items.map((item, idx) => (
        <div
          key={item.scaleId}
          className={`inline-flex w-full items-center px-2 py-1 ${
            idx % 2 === 0 ? "bg-white" : "bg-[#F8F8FC]"
          }`}
        >
          <div className="flex flex-1 items-center gap-2.5 p-2">
            <p className="text-[14px] font-normal leading-normal text-black">
              {(item.label.length > 6
                ? StringHelper.splitLabel(item.label, 28)
                : [item.label]
              ).map((line, lineIdx) => (
                <span key={`${item.scaleId}-${lineIdx}`} className="block">
                  {line}
                </span>
              ))}
            </p>
          </div>
          <div className="flex w-[90px] items-center gap-2.5 p-2">
            <p className="text-[14px] font-normal leading-normal text-black">
              {item.score}
              {t("stressCheckResultPage-staff.points")}
            </p>
          </div>
          <div className="flex w-[90px] items-center gap-2.5 p-2">
            <p className="text-[14px] font-normal leading-normal text-black">
              {item.previousScore !== undefined
                ? `${item.previousScore}${t("stressCheckResultPage-staff.points")}`
                : "ー"}
            </p>
          </div>
        </div>
      ))}
    </div>
  );
}
