import type { ComponentType } from "react";
import { useNavigate } from "@tanstack/react-router";
import { EnvConfig } from "@/configs/env.config";
import RouteConfig from "@/configs/route.config";
import DateTimeHelper from "@/helpers/date-time.helper";
import GrinningFaceIcon from "@/components/icons/grinning-face-icon";
import HealthyRecognitionIcon from "@/components/icons/healthy-recognition-icon";
import UmbrellaIcon from "@/components/icons/umbrella-icon";
import type { SvgIconProps } from "@/components/svg-icon";
import { Button } from "@/components/ui/button";
import { DonutChart } from "./donut-chart";
import ConfusedFaceIcon from "@/components/icons/confused-face";

export const WidgetInfo = () => {
  const navigate = useNavigate();

  const goToCreate = () => {
    navigate({ to: RouteConfig.ADMIN_STRESS_PROJECT_CREATE.route.to });
  };

  return (
    <div className="flex flex-col w-[341px]">
      <div className="flex flex-col gap-4">
        <div>
          <UmbrellaIcon size={40} />
        </div>
        <div className="font-bold text-[20px] leading-[32px]">
          <span>{"ストレスチェックの実施設定が"}</span>
          <br />
          <span>{"されていません。"}</span>
        </div>
        <div className="text-[14px] leading-[23px]">
          <span>
            {
              "新しくストレスチェックを作成して、従業員のメンタルヘルスケアを始めましょう。 作成後は、回答状況の確認や集団分析の結果をすぐに確認いただけます。設定方法にご不明な点があれば、お気軽にお問い合わせください。"
            }
          </span>
        </div>
      </div>
      <div className="pt-4 flex flex-col gap-2 items-center">
        <Button className="w-[300px]" onClick={goToCreate}>
          <span>{"新規実施を作成する"}</span>
        </Button>

        <a
          href={EnvConfig.VITE_CONTACT_CUSTOMER_SUPPORT}
          target="_blank"
          rel="noopener noreferrer"
        >
          <Button
            variant="outline"
            className="w-[300px] border-2 border-primary-light"
            asChild
          >
            <span>{"カスタマーサポートへ問い合わせ"}</span>
          </Button>
        </a>
      </div>
    </div>
  );
};

interface LatestPeriod {
  id: number;
  title: string | null;
  year: number | null;
  start_date: string | null;
  end_date: string | null;
  status: string | null;
  total_examinees: number;
  answered_examinees: number;
  response_rate: number | null;
}

interface WidgetInfoWithDonutChartProps {
  latestPeriod?: LatestPeriod | null;
}

export const WidgetInfoWithDonutChart = ({
  latestPeriod,
}: WidgetInfoWithDonutChartProps) => {
  return (
    <div className="flex flex-col w-[341px] gap-8 h-full">
      <div className="flex flex-col gap-4">
        <div className="flex gap-2 items-end">
          <HealthyRecognitionIcon size={32} className="text-primary-light" />
          <span className="flex items-end gap-1 font-bold">
            <span className="text-[24px] leading-[29px]">
              {latestPeriod?.year ?? "--"}
            </span>
            <span className="text-[18px] leading-[22px]">{"年度"}</span>
          </span>
        </div>
        <div className="flex flex-col gap-2.5">
          <span className="font-bold text-[16px] leading-[19px]">
            {latestPeriod?.start_date
              ? DateTimeHelper.format(
                latestPeriod?.start_date,
                "yyyy年 MM月 dd日"
              )
              : ""}
            {" ～ "}
            {latestPeriod?.end_date
              ? DateTimeHelper.format(
                latestPeriod?.end_date,
                "yyyy年 MM月 dd日"
              )
              : ""}
          </span>
          <span className="text-[14px] leading-[17px]">
            {latestPeriod?.title ?? ""}
          </span>
        </div>
      </div>
      <div className="flex items-center justify-center">
        <DonutChart rate={latestPeriod?.response_rate ?? null} />
      </div>
    </div>
  );
};

interface IHorizontalBar {
  label: string;
  value: number | null | undefined;
  maxValue: number;
  color?: string;
  suffix?: string;
}

interface WidgetCardAnalysisProps {
  icon: ComponentType<SvgIconProps>;
  title: string;
  point?: number | null;
  unit?: string;
  bars?: IHorizontalBar[];
  evaluation?: string | null;
}

export const WidgetCardAnalysis = ({
  icon: Icon,
  title,
  point,
  unit,
  bars,
  evaluation,
}: WidgetCardAnalysisProps) => {
  return (
    <div className="rounded-[16px] border border-[#ACADB0] px-4 py-6 flex flex-col gap-4 h-[200px] justify-center">
      <div className="flex gap-2 items-center">
        <div>
          <Icon size={28} />
        </div>
        <div className="font-bold text-[16px] leading-[19px]">
          <span>{title}</span>
        </div>
      </div>
      <div className="px-2 flex flex-col gap-4">
        <div className="px-2 flex items-center justify-between">
          <div className="flex gap-1 font-bold items-end">
            <span className="text-[28px] leading-[34px]">
              {point != null ? point : "--"}
            </span>
            <span className="text-[16px] leading-[19px]">{unit}</span>
          </div>
          <div className="flex gap-1 items-center">
            <span className="font-medium text-[12px] leading-[14px]">
              {"評価 : "}
            </span>
            {evaluation ? (
              <span className="flex gap-1 items-center">
                {evaluation === "good" ? (
                  <GrinningFaceIcon size={20} className="text-[#15CA42]" />
                ) : (
                  <ConfusedFaceIcon size={20} className="text-[#F5A623]" />
                )}
                <span
                  className="font-bold text-[12px] leading-[14px]"
                >
                  {evaluation === "good" ? "良好" : "注意"}
                </span>
              </span>
            ) : (
              <span className="flex gap-1 items-center">
                <ConfusedFaceIcon size={20} />
                <span className="font-bold text-[12px] leading-[14px] text-[#ACADB0]">
                  {"不明"}
                </span>
              </span>
            )}
          </div>
        </div>
        <div className="flex flex-col gap-1">
          {bars?.map((bar, index) => (
            <HorizontalBar
              key={`bar_${bar.label}_${index}`}
              {...bar}
            />
          ))}
        </div>
      </div>
    </div>
  );
};

export const HorizontalBar = ({
  label,
  value,
  maxValue,
  color,
  suffix,
}: IHorizontalBar) => {
  const pct = value != null ? Math.min((value / maxValue) * 100, 100) : 0;

  return (
    <div>
      <div className="flex gap-2">
        <span className="w-[50px] font-medium text-[12px] leading-[14px] text-right">
          {label}
        </span>
        <div className="flex-1 flex items-center">
          <div className="bg-[#E5E5E5] rounded-[64px] w-full h-2 relative">
            <span
              className="rounded-[64px] h-2 absolute w-[0%]"
              style={{ width: `${pct}%`, backgroundColor: color ?? "#ACADB0" }}
            ></span>
          </div>
        </div>
      </div>
      {suffix && (
        <div className="flex gap-2">
          <span className="w-[50px]" />
          <span className="font-medium text-[12px] leading-[14px] text-[#7C838C]">
            {suffix}
          </span>
        </div>
      )}
    </div>
  );
};
