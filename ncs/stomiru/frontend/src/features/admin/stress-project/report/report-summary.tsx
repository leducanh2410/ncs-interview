import { WidgetCardAnalysis } from "../../dashboard/dashboard-widgets";
import { DonutChart } from "../../dashboard/donut-chart";
import { WidgetBarChart } from "../../dashboard/widget-bar-chart";
import type { StressCheck } from "@/types/models/stress-check";
import type { StressReportData } from "@/types/models/stress-report";
import ConceptSharingIcon from "@/components/icons/concept-sharing-icon";
import RadarChartIcon from "@/components/icons/radar-chart-icon";
import { ReportHeader } from "./report-header";

interface ReportSummaryProps {
  report: StressReportData;
  stressProject: StressCheck | null;
}

export const ReportSummary = ({
  report,
  stressProject,
}: ReportSummaryProps) => {
  return (
    <div className="flex w-full flex-col gap-8">
      {/* Top Section: Donut Chart + Bar Chart */}
      <div className="inline-flex w-full items-start gap-12">
        <div className="inline-flex flex-1 flex-col items-center gap-8">
          <div className="w-full">
            <ReportHeader stressProject={stressProject} />
          </div>
          <div className="flex items-center justify-center">
            <DonutChart rate={report.summary.response_rate} />
          </div>
        </div>
        <div className="w-[491px]">
          <WidgetBarChart
            chartData={{
              psychologicalBurden: {
                current:
                  report.stress_factors.psychological_burden_quantity.current,
                previous:
                  report.stress_factors.psychological_burden_quantity.previous,
                nationalAverage:
                  report.stress_factors.psychological_burden_quantity.national,
                evaluation:
                  report.stress_factors.psychological_burden_quantity.current <=
                  report.stress_factors.psychological_burden_quantity.national
                    ? "good"
                    : "caution",
              },
              jobControl: {
                current: report.stress_factors.job_control.current,
                previous: report.stress_factors.job_control.previous,
                nationalAverage: report.stress_factors.job_control.national,
                evaluation:
                  report.stress_factors.job_control.current >=
                  report.stress_factors.job_control.national
                    ? "good"
                    : "caution",
              },
              supervisorSupport: {
                current: report.support.supervisor_support.current,
                previous: report.support.supervisor_support.previous,
                nationalAverage: report.support.supervisor_support.national,
                evaluation:
                  report.support.supervisor_support.current >=
                  report.support.supervisor_support.national
                    ? "good"
                    : "caution",
              },
              colleagueSupport: {
                current: report.support.colleague_support.current,
                previous: report.support.colleague_support.previous,
                nationalAverage: report.support.colleague_support.national,
                evaluation:
                  report.support.colleague_support.current >=
                  report.support.colleague_support.national
                    ? "good"
                    : "caution",
              },
            }}
            isEmpty={false}
            isFinished={true}
          />
        </div>
      </div>

      {/* Bottom Section: Analysis Cards */}
      <div className="inline-flex h-[200px] w-full items-start gap-4">
        <div className="flex-1">
          <WidgetCardAnalysis
            icon={ConceptSharingIcon}
            title="高ストレス者割合"
            point={report.high_stress.ratio}
            unit="%"
            evaluation={report.high_stress.evaluation}
            bars={[
              {
                label: "本集団",
                value: report.high_stress.ratio,
                color:
                  report.high_stress.evaluation === "good"
                    ? "#15CA42"
                    : "#FFD722",
                maxValue: 100,
              },
              {
                label: "前回",
                value: report.high_stress.previous_ratio,
                color: "#7C838C",
                maxValue: 100,
              },
              {
                label: "全国平均",
                value: report.high_stress.national_average,
                color: "#ACADB0",
                suffix: `${report.high_stress.national_average}%`,
                maxValue: 100,
              },
            ]}
          />
        </div>
        <div className="flex-1">
          <WidgetCardAnalysis
            icon={RadarChartIcon}
            title="総合健康リスク"
            point={report.health_risk.total_score}
            unit="点"
            evaluation={report.health_risk.evaluation}
            bars={[
              {
                label: "本集団",
                value: report.health_risk.total_score,
                color: "#91D5FF",
                maxValue: 510,
              },
              {
                label: "前回",
                value: report.health_risk.previous_score,
                color: "#7C838C",
                maxValue: 510,
              },
              {
                label: "全国平均",
                value: report.health_risk.national_average,
                color: "#ACADB0",
                suffix: `${report.health_risk.national_average}点`,
                maxValue: 510,
              },
            ]}
          />
        </div>
      </div>
    </div>
  );
};
