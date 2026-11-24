import type { StressReportData } from "@/types/models/stress-report";
import EllipseIcon from "@/components/icons/ellipse-icon";
import HoldSeedsIcon from "@/components/icons/hold-seeds-icon";
import LaptopIcon from "@/components/icons/laptop-icon";
import PolygonIcon from "@/components/icons/polygon-icon";
import TriangleIcon from "@/components/icons/triangle-icon";
import { SupportHeatmapChart } from "./support-heatmap-chart";
import { WorkloadHeatmapChart } from "./workload-heatmap-chart";

interface ReportHeatmapProps {
  scatterData: StressReportData["scatter_data"];
  stressFactors: StressReportData["stress_factors"];
  support: StressReportData["support"];
  healthRisk: StressReportData["health_risk"];
}

const formatScore = (val: number | null | undefined): string =>
  val != null ? val.toFixed(1) : "--";

const formatRisk = (val: number | null | undefined): string =>
  val != null ? String(val) : "--";

export const ReportHeatmap = ({
  scatterData,
  stressFactors,
  support,
}: ReportHeatmapProps) => {
  const workload = scatterData.psychological_workload;
  const supportData = scatterData.support;

  return (
    <div className="flex flex-col gap-[16px] pt-[24px]">
      {/* Title */}
      <div className="flex gap-[16px] items-start">
        <div className="bg-[#15CA42] h-[22px] shrink-0 w-[8px]" />
        <h2 className="font-bold text-[18px] leading-normal">
          仕事のストレス判定図
        </h2>
      </div>

      {/* Two Heatmaps Side by Side */}
      <div className="flex gap-[40px] justify-center p-[24px]">
        {/* Left Heatmap - Workload/Control */}
        <div className="flex-1 flex flex-col gap-[24px] items-center">
          <div className="flex gap-[8px] items-center w-full">
            <LaptopIcon size={28} />
            <h3 className="font-bold text-[16px] leading-normal text-center">
              仕事の量的負荷/コントロール
            </h3>
          </div>

          <div className="flex flex-col gap-[16px] items-center">
            <WorkloadHeatmapChart
              currentX={workload.current.x}
              currentY={workload.current.y}
              previousX={workload.previous?.x}
              previousY={workload.previous?.y}
              nationalX={workload.national.x}
              nationalY={workload.national.y}
            />

            <div className="flex gap-[24px] h-[22px] items-start justify-center w-[412px]">
              <div className="flex gap-[4px] h-[22px] items-center">
                <TriangleIcon />
                <p className="font-medium text-[14px] leading-normal">本集団</p>
              </div>
              <div className="flex gap-[4px] h-[22px] items-center">
                <EllipseIcon />
                <p className="font-medium text-[14px] leading-normal">前回</p>
              </div>
              <div className="flex gap-[4px] h-[22px] items-center">
                <PolygonIcon />
                <p className="font-medium text-[14px] leading-normal">全国</p>
              </div>
            </div>
          </div>

          {/* Workload Data Table */}
          <div className="flex flex-col items-start px-[16px] w-full">
            <div className="bg-[#F8F8FC] border-b border-[#EBEBF5] flex h-[40px] items-center px-[8px] rounded-tl-[8px] rounded-tr-[8px] w-full">
              <div className="flex-1 h-[40px] flex items-center pl-[12px] pr-[8px] py-[4px]">
                <p className="font-bold text-[14px] leading-normal">項目</p>
              </div>
              <div className="w-[65px] h-[40px] flex items-center justify-center pl-[12px] pr-[8px] py-[4px]">
                <p className="font-bold text-[14px] leading-normal">本集団</p>
              </div>
              <div className="w-[40px] h-[40px] flex items-center justify-center p-[4px]">
                <p className="text-[14px] leading-normal">前回</p>
              </div>
              <div className="w-[40px] h-[40px] flex items-center justify-center p-[4px]">
                <p className="text-[14px] leading-normal">全国</p>
              </div>
            </div>

            <div className="bg-white flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">
                  心理的な仕事の負担(量) の平均
                </p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatScore(
                    stressFactors.psychological_burden_quantity.current
                  )}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(
                    stressFactors.psychological_burden_quantity.previous
                  )}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(
                    stressFactors.psychological_burden_quantity.national
                  )}
                </p>
              </div>
            </div>

            <div className="bg-[#F8F8FC] flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">
                  仕事のコントロール度の平均
                </p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatScore(stressFactors.job_control.current)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(stressFactors.job_control.previous)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(stressFactors.job_control.national)}
                </p>
              </div>
            </div>

            <div className="bg-white flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">健康リスク（仕事）</p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatRisk(workload.health_risk)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatRisk(workload.previous_health_risk)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {workload.national_health_risk ?? 100}
                </p>
              </div>
            </div>
          </div>

          <div className="text-[12px] leading-[22px] font-[350] w-full">
            <p className="font-bold mb-0">
              ※健康リスク（仕事の量的負荷/コントロール）
            </p>
            <p>
              100×EXP((量的負荷の平均点－8.7)×0.076＋(仕事のコントロール度の平均点－7.9)×-0.089)
            </p>
          </div>
        </div>

        {/* Right Heatmap - Support */}
        <div className="flex-1 flex flex-col gap-[24px] items-center">
          <div className="flex gap-[8px] items-center w-full">
            <HoldSeedsIcon size={28} />
            <h3 className="font-bold text-[16px] leading-normal text-center">
              職場の支援
            </h3>
          </div>

          <div className="flex flex-col gap-[16px] items-center">
            <SupportHeatmapChart
              currentX={supportData.current.x}
              currentY={supportData.current.y}
              previousX={supportData.previous?.x}
              previousY={supportData.previous?.y}
              nationalX={supportData.national.x}
              nationalY={supportData.national.y}
            />

            <div className="flex gap-[24px] h-[22px] items-start justify-center w-[412px]">
              <div className="flex gap-[4px] h-[22px] items-center">
                <TriangleIcon />
                <p className="font-medium text-[14px] leading-normal">本集団</p>
              </div>
              <div className="flex gap-[4px] h-[22px] items-center">
                <EllipseIcon />
                <p className="font-medium text-[14px] leading-normal">前回</p>
              </div>
              <div className="flex gap-[4px] h-[22px] items-center">
                <PolygonIcon />
                <p className="font-medium text-[14px] leading-normal">全国</p>
              </div>
            </div>
          </div>

          {/* Support Data Table */}
          <div className="flex flex-col items-start px-[16px] w-full">
            <div className="bg-[#F8F8FC] border-b border-[#EBEBF5] flex h-[40px] items-center px-[8px] rounded-tl-[8px] rounded-tr-[8px] w-full">
              <div className="flex-1 h-[40px] flex items-center pl-[12px] pr-[8px] py-[4px]">
                <p className="font-bold text-[14px] leading-normal">項目</p>
              </div>
              <div className="w-[65px] h-[40px] flex items-center justify-center pl-[12px] pr-[8px] py-[4px]">
                <p className="font-bold text-[14px] leading-normal">本集団</p>
              </div>
              <div className="w-[40px] h-[40px] flex items-center justify-center p-[4px]">
                <p className="text-[14px] leading-normal">前回</p>
              </div>
              <div className="w-[40px] h-[40px] flex items-center justify-center p-[4px]">
                <p className="text-[14px] leading-normal">全国</p>
              </div>
            </div>

            <div className="bg-white flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">
                  上司からのサポートの平均
                </p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatScore(support.supervisor_support.current)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(support.supervisor_support.previous)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(support.supervisor_support.national)}
                </p>
              </div>
            </div>

            <div className="bg-[#F8F8FC] flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">
                  同僚からのサポートの平均
                </p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatScore(support.colleague_support.current)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(support.colleague_support.previous)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatScore(support.colleague_support.national)}
                </p>
              </div>
            </div>

            <div className="bg-white flex items-center px-[8px] py-[4px] w-full">
              <div className="flex-1 flex items-center p-[8px]">
                <p className="text-[14px] leading-normal">健康リスク（支援）</p>
              </div>
              <div className="w-[60px] flex items-center justify-center p-[8px]">
                <p className="font-bold text-[14px] leading-normal">
                  {formatRisk(supportData.health_risk)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {formatRisk(supportData.previous_health_risk)}
                </p>
              </div>
              <div className="w-[40px] flex items-center justify-center px-[4px] py-[8px]">
                <p className="text-[14px] leading-normal">
                  {supportData.national_health_risk ?? 99}
                </p>
              </div>
            </div>
          </div>

          <div className="text-[12px] leading-[22px] font-[350] w-full">
            <p className="font-bold mb-0">※健康リスク（職場の支援）</p>
            <p>
              100×EXP((上司からのサポート平均点－7.5)×-0.097＋(同僚からのサポート平均点－8.1)×-0.097)
            </p>
          </div>
        </div>
      </div>
    </div>
  );
};
