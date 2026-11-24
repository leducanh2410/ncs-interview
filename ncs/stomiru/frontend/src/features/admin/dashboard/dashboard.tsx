import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import ConceptSharingIcon from "@/components/icons/concept-sharing-icon";
import RadarChartIcon from "@/components/icons/radar-chart-icon";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  WidgetCardAnalysis,
  WidgetInfo,
  WidgetInfoWithDonutChart,
} from "./dashboard-widgets";
import { useGetDashboardDataQuery } from "./dashboard.api";
import { WidgetBarChart } from "./widget-bar-chart";
import { cn } from "@/lib/utils";

const Dashboard = () => {
  const { data: pageData, isFetching } = useGetDashboardDataQuery({});
  const dashboardData = pageData?.data;

  const isEmpty = dashboardData?.is_empty ?? true;
  const isFinished =
    dashboardData?.latest_period?.status ===
    StressCheckStatusEnum.Finished.value;
  const isInProgress = !isEmpty && !isFinished;

  return (
    <div className="flex flex-col gap-8 max-w-[880px] w-full mx-auto pb-20 pt-14">
      <div className="flex flex-col gap-8">
        <div className="flex flex-col gap-2">
          <div className="flex flex-col gap-8">
            <div className="flex gap-12">
              <div>
                {isFetching ? (
                  <Skeleton className="flex flex-col w-[341px] h-full" />
                ) : (
                  <>
                    {isEmpty ? (
                      <WidgetInfo />
                    ) : (
                      <WidgetInfoWithDonutChart
                        latestPeriod={dashboardData?.latest_period ?? null}
                      />
                    )}
                  </>
                )}
              </div>
              <div className="flex-1">
                <WidgetBarChart
                  chartData={dashboardData?.chart_data ? {
                    psychologicalBurden: dashboardData.chart_data.psychological_burden,
                    jobControl: dashboardData.chart_data.job_control,
                    supervisorSupport: dashboardData.chart_data.supervisor_support,
                    colleagueSupport: dashboardData.chart_data.colleague_support,
                  } : undefined}
                  isEmpty={isEmpty}
                  isFinished={isFinished}
                />
              </div>
            </div>
            <div className="flex gap-4">
              <div className="flex-1">
                <WidgetCardAnalysis
                  icon={ConceptSharingIcon}
                  title="高ストレス者割合"
                  point={dashboardData?.high_stress_ratio}
                  unit="%"
                  evaluation={dashboardData?.high_stress_evaluation}
                  bars={[
                    {
                      label: "本集団",
                      value: dashboardData?.high_stress_ratio,
                      color: dashboardData?.high_stress_evaluation === "good" ? "#15CA42" : dashboardData?.high_stress_evaluation ? "#FFD722" : "#E5E5E5",
                      maxValue: 100,
                    },
                    {
                      label: "前回",
                      value: dashboardData?.high_stress_ratio_previous,
                      color: "#7C838C",
                      maxValue: 100,
                    },
                    {
                      label: "全国平均",
                      value: 10,
                      color: "#ACADB0",
                      suffix: "10.0%",
                      maxValue: 100,
                    },
                  ]}
                />
              </div>
              <div className="flex-1">
                <WidgetCardAnalysis
                  icon={RadarChartIcon}
                  title="総合健康リスク"
                  point={dashboardData?.health_risk}
                  unit="点"
                  evaluation={dashboardData?.health_risk_evaluation}
                  bars={[
                    {
                      label: "本集団",
                      value: dashboardData?.health_risk,
                      color: dashboardData?.health_risk_evaluation ? "#91D5FF" : "#E5E5E5",
                      maxValue: 200,
                    },
                    {
                      label: "前回",
                      value: dashboardData?.health_risk_previous,
                      color: "#7C838C",
                      maxValue: 200,
                    },
                    {
                      label: "全国平均",
                      value: 100,
                      color: "#ACADB0",
                      suffix: "100点",
                      maxValue: 200,
                    },
                  ]}
                />
              </div>
            </div>
          </div>
          {isInProgress && (
            <p className="text-[14px] leading-[23px] text-center">
              {"※実施期間終了までは受検率のみ表示になります。"}
            </p>
          )}
        </div>
        <div className="pt-4 flex flex-col items-center justify-center">
          <Button
            className={cn(
              "w-[300px] rounded-[20px]",
              !isFinished && "bg-[#D0D5D7] text-input"
            )}
            disabled={!isFinished}
            onClick={() => {
              if (isFinished && dashboardData?.latest_period?.id) {
                window.location.href = `/admin/stress-project/${dashboardData.latest_period.id}/report`;
              }
            }}
          >
            <span>{"ストレスチェック集団分析レポート"}</span>
          </Button>
        </div>
      </div>
    </div>
  );
};

export default Dashboard;
