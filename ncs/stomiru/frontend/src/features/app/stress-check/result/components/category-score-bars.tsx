import likeIcon from "@/assets/icons/like.svg";
import timelineIcon from "@/assets/icons/timeline.svg";
import warehousingIcon from "@/assets/icons/warehousing.svg";
import { useTranslation } from "@/hooks/use-translation";
import DisappointedFaceIcon from "@/components/icons/disappointed-face-icon";
import GrinningFaceTightlyEyesIcon from "@/components/icons/grinning-face-tighly-eyes";

export function CategoryScoreBars({
  categoryA,
  categoryB,
  categoryC,
  previousCategoryA,
  previousCategoryB,
  previousCategoryC,
  maxA,
  maxB,
  maxC,
}: {
  categoryA: number;
  categoryB: number;
  categoryC: number;
  previousCategoryA?: number;
  previousCategoryB?: number;
  previousCategoryC?: number;
  maxA: number;
  maxB: number;
  maxC: number;
}) {
  const { t, tVar } = useTranslation();

  const categories = [
    {
      name: t("stressCheckResultPage-staff.stressFactors"),
      score: categoryA,
      previousScore: previousCategoryA,
      max: maxA,
      highThreshold: 9,
      lowThreshold: 43,
      icon: timelineIcon,
      color: "#9747FF",
    },
    {
      name: t("stressCheckResultPage-staff.mentalReactions"),
      score: categoryB,
      previousScore: previousCategoryB,
      max: maxB,
      highThreshold: 6,
      lowThreshold: 30,
      icon: likeIcon,
      color: "#FFD722",
    },
    {
      name: t("stressCheckResultPage-staff.otherFactors"),
      score: categoryC,
      previousScore: previousCategoryC,
      max: maxC,
      highThreshold: 3,
      lowThreshold: 15,
      icon: warehousingIcon,
      color: "#91D5FF",
    },
  ];

  return (
    <div className="flex flex-1 flex-col justify-center gap-4">
      {categories.map((cat, idx) => {
        const percentage = (cat.score / cat.max) * 100;

        return (
          <div key={idx} className="flex flex-col gap-1">
            <div className="inline-flex items-center gap-4">
              <div className="flex w-[270px] items-center gap-2">
                <img src={cat.icon} alt="" width={20} height={20} />
                <span className="text-[14px] font-medium text-black">
                  {cat.name}
                </span>
              </div>
              <div className="inline-flex items-end">
                <span className="text-[20px] font-bold leading-none text-black">
                  {cat.score}
                </span>
                <span className="text-[12px] font-bold leading-[18px] text-black">
                  {t("stressCheckResultPage-staff.points")}
                </span>
                <span className="text-[12px] font-normal leading-[18px] text-black">
                  {`（${t("common.previous")}：${cat.previousScore ?? "ー"}）`}
                </span>
              </div>
            </div>

            <div className="h-2 overflow-hidden rounded-[64px] bg-[#E5E5E5]">
              <div className="h-full rounded-[64px]">
                <div
                  className="h-full rounded-[64px] transition-all duration-700"
                  style={{
                    width: `${percentage}%`,
                    backgroundColor: cat.color,
                  }}
                />
              </div>
            </div>

            <div className="inline-flex w-full items-start justify-between">
              <div className="flex items-center gap-2">
                <DisappointedFaceIcon width={16} height={16} />
                <span className="text-[12px] font-medium text-[#7C838C]">
                  {tVar("stressCheckResultPage-staff.highStressScoreDynamic", {
                    score: String(cat.highThreshold),
                  })}
                </span>
              </div>
              <div className="flex items-center gap-2">
                <span className="text-[12px] font-medium text-[#7C838C]">
                  {tVar("stressCheckResultPage-staff.lowStressScoreDynamic", {
                    score: String(cat.lowThreshold),
                  })}
                </span>
                <GrinningFaceTightlyEyesIcon width={16} height={16} />
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}
