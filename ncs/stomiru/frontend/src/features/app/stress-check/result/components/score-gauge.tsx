import { useTranslation } from "@/hooks/use-translation";

export function ScoreGauge({
  score,
  maxScore,
  previousScore,
  color,
}: {
  score: number;
  maxScore: number;
  previousScore?: number;
  color: string;
}) {
  const { t } = useTranslation();
  const pct = Math.min((score / maxScore) * 100, 100);
  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  const strokeDasharray = `${(pct / 100) * circumference} ${circumference}`;

  return (
    <div className="relative h-[180px] w-[180px]">
      <svg viewBox="0 0 160 160" className="w-full h-full -rotate-90">
        <circle
          cx="80"
          cy="80"
          r={radius}
          fill="none"
          stroke="#e8e8e8"
          strokeWidth="14"
        />
        <circle
          cx="80"
          cy="80"
          r={radius}
          fill="none"
          stroke={color}
          strokeWidth="14"
          strokeDasharray={strokeDasharray}
          strokeLinecap="round"
          style={{
            transition: "stroke-dasharray 0.8s ease-in-out",
          }}
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="text-[14px] font-bold tracking-[0.4px] text-black">
          {t("stressCheckResultPage-staff.totalScore")}
        </span>
        <span className="inline-flex items-end pb-1">
          <span className="text-[32px] font-bold leading-none text-black">
            {score}
          </span>
          <span className="text-[24px] font-normal leading-none text-black">
            /{maxScore}
          </span>
          <span className="text-[12px] font-normal leading-[20px] text-[#262626]">
            {t("stressCheckResultPage-staff.points")}
          </span>
        </span>
        {previousScore !== undefined && (
          <span className="text-[12px] font-normal tracking-[0.4px] text-black">
            {t("common.previous")}：{previousScore}
          </span>
        )}
      </div>
    </div>
  );
}
