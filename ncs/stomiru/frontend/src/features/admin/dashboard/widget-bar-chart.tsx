import {
  Bar,
  BarChart,
  CartesianGrid,
  LabelList,
  ResponsiveContainer,
  XAxis,
  YAxis,
} from "recharts";
import StringHelper from "@/helpers/string.helper";
import ConfusedFaceIcon from "@/components/icons/confused-face";
import FaceWithoutMouthIcon from "@/components/icons/face-without-mouth";
import FileSearchIcon from "@/components/icons/file-search-icon";
import GrinningFaceIcon from "@/components/icons/grinning-face-icon";

interface CategoryData {
  previous?: number | null;
  current?: number | null;
  nationalAverage?: number | null;
  evaluation?: string | null;
}

interface WidgetBarChartProps {
  chartData?: {
    psychologicalBurden?: CategoryData;
    jobControl?: CategoryData;
    supervisorSupport?: CategoryData;
    colleagueSupport?: CategoryData;
  };
  isEmpty?: boolean;
  isFinished?: boolean;
}

const NATIONAL_AVERAGES = {
  psychologicalBurden: 8.7,
  jobControl: 7.9,
  supervisorSupport: 7.5,
  colleagueSupport: 8.1,
};

const BAR_COLORS = {
  currentFinished: "#15CA42",
  currentEmpty: "#D0D5D7",
  nationalAverage: "#ACADB0",
  previousFinished: "#7C838C",
  previousEmpty: "#979797",
};

const renderBarLabel = (props: any) => {
  const x = Number(props.x ?? 0);
  const y = Number(props.y ?? 0);
  const width = Number(props.width ?? 0);
  const value = props.value as number | null | undefined;
  const display = value != null && value > 0 ? value.toFixed(1) : "--";
  return (
    <text
      x={x + width / 2}
      y={y - 6}
      textAnchor="middle"
      fontSize={11}
      fontWeight={500}
      fill="#666"
    >
      {display}
    </text>
  );
};

function EvaluationBadge({
  evaluation,
}: {
  evaluation: string | null | undefined;
}) {
  if (!evaluation) {
    return (
      <span className="inline-flex items-center gap-1">
        <ConfusedFaceIcon size={20} className="text-[#ACADB0]" />
        <span className="text-[11px] text-[#ACADB0]">不明</span>
      </span>
    );
  }
  const isGood = evaluation === "good";
  return (
    <span className="inline-flex items-center gap-1 mt-2">
      {isGood ? (
        <GrinningFaceIcon size={20} className="text-[#15CA42]" />
      ) : (
        <ConfusedFaceIcon size={20} className="text-[#F5A623]" />
      )}
      <span className="text-[11px] font-bold">{isGood ? "良好" : "注意"}</span>
    </span>
  );
}

const renderCustomXAxisTick = ({ x, y, payload }: any) => {
  const lines = StringHelper.splitLabel(payload.value, 7);
  return (
    <text x={x} y={y + 15} textAnchor="middle" fontSize={11} fill="#333">
      {lines.map((line, i) => (
        <tspan key={i} x={x} dy={i === 0 ? 0 : 15}>
          {line}
        </tspan>
      ))}
    </text>
  );
};

export const WidgetBarChart = ({
  chartData,
  isEmpty,
  isFinished,
}: WidgetBarChartProps) => {
  const getEvaluation = (
    key: string,
    current: number | null | undefined,
    nationalAverage: number
  ) => {
    if (current == null) return null;
    if (key === "psychological_burden") {
      return current <= nationalAverage ? "good" : "caution";
    }
    return current >= nationalAverage ? "good" : "caution";
  };

  const barData = [
    {
      name: "心理的な仕事の負担（量）",
      previous: chartData?.psychologicalBurden?.previous ?? null,
      current: chartData?.psychologicalBurden?.current ?? null,
      nationalAverage:
        chartData?.psychologicalBurden?.nationalAverage ??
        NATIONAL_AVERAGES.psychologicalBurden,
      evaluation: getEvaluation(
        "psychological_burden",
        chartData?.psychologicalBurden?.current,
        chartData?.psychologicalBurden?.nationalAverage ??
          NATIONAL_AVERAGES.psychologicalBurden
      ),
    },
    {
      name: "仕事のコントロール度",
      previous: chartData?.jobControl?.previous ?? null,
      current: chartData?.jobControl?.current ?? null,
      nationalAverage:
        chartData?.jobControl?.nationalAverage ?? NATIONAL_AVERAGES.jobControl,
      evaluation: getEvaluation(
        "job_control",
        chartData?.jobControl?.current,
        chartData?.jobControl?.nationalAverage ?? NATIONAL_AVERAGES.jobControl
      ),
    },
    {
      name: "上司からのサポート",
      previous: chartData?.supervisorSupport?.previous ?? null,
      current: chartData?.supervisorSupport?.current ?? null,
      nationalAverage:
        chartData?.supervisorSupport?.nationalAverage ??
        NATIONAL_AVERAGES.supervisorSupport,
      evaluation: getEvaluation(
        "supervisor_support",
        chartData?.supervisorSupport?.current,
        chartData?.supervisorSupport?.nationalAverage ??
          NATIONAL_AVERAGES.supervisorSupport
      ),
    },
    {
      name: "同僚からのサポート",
      previous: chartData?.colleagueSupport?.previous ?? null,
      current: chartData?.colleagueSupport?.current ?? null,
      nationalAverage:
        chartData?.colleagueSupport?.nationalAverage ??
        NATIONAL_AVERAGES.colleagueSupport,
      evaluation: getEvaluation(
        "colleague_support",
        chartData?.colleagueSupport?.current,
        chartData?.colleagueSupport?.nationalAverage ??
          NATIONAL_AVERAGES.colleagueSupport
      ),
    },
  ];

  const safeBarData = barData.map((d) => ({
    ...d,
    _previous: d.previous != null ? d.previous : 2.15,
    _current: d.current != null && d.current >= 2 ? d.current : 2.15,
    _nationalAverage:
      d.nationalAverage != null && d.nationalAverage >= 2
        ? d.nationalAverage
        : 2.15,
  }));

  const previousColor = isFinished
    ? BAR_COLORS.previousFinished
    : BAR_COLORS.previousEmpty;
  const currentColor = isFinished
    ? BAR_COLORS.currentFinished
    : BAR_COLORS.currentEmpty;

  const showOverlay = !isEmpty && !isFinished;

  return (
    <div className="w-full relative">
      <div className="flex items-center justify-center gap-5 mb-4 pr-6">
        <div className="flex items-center gap-1.5">
          <div
            className="w-3 h-3 rounded-[2px]"
            style={{ backgroundColor: previousColor }}
          />
          <span className="text-[12px] leading-[14px]">{"前回"}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div
            className="w-3 h-3 rounded-[2px]"
            style={{ backgroundColor: currentColor }}
          />
          <span className="text-[12px] leading-[14px]">{"本集団"}</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div
            className="w-3 h-3 rounded-[2px]"
            style={{ backgroundColor: BAR_COLORS.nationalAverage }}
          />
          <span className="text-[12px] leading-[14px]">{"全国平均"}</span>
        </div>
      </div>
      <ResponsiveContainer width="100%" height={300}>
        <BarChart
          data={safeBarData}
          margin={{ top: 20, right: 10, left: -10, bottom: 0 }}
          barCategoryGap="20%"
          barGap={2}
        >
          <CartesianGrid
            strokeDasharray="3 3"
            vertical={false}
            stroke="#E5E7EB"
          />
          <XAxis
            dataKey="name"
            tick={renderCustomXAxisTick}
            tickLine={false}
            axisLine={{ stroke: "#E5E7EB" }}
            interval={0}
            height={50}
          />
          <YAxis
            domain={[2, 12]}
            ticks={[2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]}
            tick={{ fontSize: 11, fill: "#999" }}
            tickLine={false}
            axisLine={false}
          />
          <Bar
            dataKey="_previous"
            fill={previousColor}
            radius={[2, 2, 0, 0]}
            maxBarSize={12}
          >
            <LabelList dataKey="previous" content={renderBarLabel} />
          </Bar>
          <Bar
            dataKey="_current"
            fill={currentColor}
            radius={[2, 2, 0, 0]}
            maxBarSize={12}
          >
            <LabelList dataKey="current" content={renderBarLabel} />
          </Bar>
          <Bar
            dataKey="_nationalAverage"
            fill={BAR_COLORS.nationalAverage}
            radius={[2, 2, 0, 0]}
            maxBarSize={12}
          >
            <LabelList dataKey="nationalAverage" content={renderBarLabel} />
          </Bar>
        </BarChart>
      </ResponsiveContainer>

      <div className="flex justify-around ml-5 mt-1 px-4">
        {barData.map((d, idx) => (
          <EvaluationBadge key={idx} evaluation={d.evaluation} />
        ))}
      </div>

      {showOverlay && (
        <div className="absolute top-[30px] left-0 right-0 bottom-[35px] bg-white/80 z-20 flex flex-col items-center justify-center">
          <FileSearchIcon size={32} className="mb-2" />
          <span className="font-bold text-[16px] leading-[19px] mb-1">
            {"集計中"}
          </span>
          <span className="text-[12px] leading-[14px]">
            {"実施期間終了までは受検率のみ表示になります。"}
          </span>
        </div>
      )}
    </div>
  );
};
