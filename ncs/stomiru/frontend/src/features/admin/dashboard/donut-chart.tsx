import ConfusedFaceIcon from "@/components/icons/confused-face";
import GrinningFaceIcon from "@/components/icons/grinning-face-icon";

function EvaluationBadge({ evaluation }: { evaluation?: string | null }) {
  if (!evaluation) {
    return (
      <span className="inline-flex items-center gap-1 mt-2">
        <span className="w-3.5 h-3.5 rounded-full bg-[#BFBFBF] inline-flex items-center justify-center">
          <span className="text-[8px] text-white font-bold">?</span>
        </span>
        <span className="text-[12px] font-bold leading-[14px] tracking-[0.5px]">
          {"不明"}
        </span>
      </span>
    );
  }
  const isGood = evaluation === "good";
  return (
    <span className="inline-flex items-center gap-1 mt-2">
      {isGood ? (
        <GrinningFaceIcon
          size={20}
          className="text-primary-light"
        />
      ) : (
        <ConfusedFaceIcon
          size={20}
          className="text-[#E5E5E5]"
        />
      )}
      <span className="text-[12px] font-bold leading-[14px] tracking-[0.5px]">
        {isGood ? "良好" : "注意"}
      </span>
    </span>
  );
}

export const DonutChart = ({ rate }: { rate: number | null }) => {
  const value = rate ?? 0;
  const radius = 83;
  const stroke = 14;
  const center = radius + stroke / 2;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (value / 100) * circumference;
  const isGood = value >= 80;

  return (
    <div
      className="relative flex items-center justify-center"
      style={{ width: center * 2, height: center * 2 }}
    >
      <svg width={center * 2} height={center * 2} className="-rotate-90">
        <circle
          cx={center}
          cy={center}
          r={radius}
          fill="none"
          stroke="#E5E5E5"
          strokeWidth={stroke}
        />
        <circle
          cx={center}
          cy={center}
          r={radius}
          fill="none"
          stroke="#9747FF"
          strokeWidth={stroke}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
        />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center">
        <span className="font-bold text-[18px] leading-[22px] tracking-[-0.4px]">
          受検率
        </span>
        <span className="font-bold text-[32px] leading-[38px] tracking-[-0.4px]">
          {rate != null ? rate.toFixed(1) : "--"}
          <span className="font-normal text-[12px] text-[#262626] leading-[20px] tracking-[-0.4px]">
            %
          </span>
        </span>
        <EvaluationBadge
          evaluation={rate != null ? (isGood ? "good" : "caution") : null}
        />
      </div>
    </div>
  );
};
