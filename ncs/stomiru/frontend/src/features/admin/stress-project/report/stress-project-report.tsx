import { useRef, useState } from "react";
import {
  type StressProjectReportResponse,
  type StressProjectResponse,
} from "../stress-project.api";
import { pdf } from "@react-pdf/renderer";
import { useNavigate } from "@tanstack/react-router";
import { toPng } from "html-to-image";
import type { StressReportData } from "@/types/models/stress-report";
import RouteConfig from "@/configs/route.config";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import ExternalIcon from "@/components/icons/external-icon";
import { Button } from "@/components/ui/button";
import { ReportAnalysisCards } from "./report-analysis-cards";
import { ReportDescription } from "./report-description";
import { ReportDetailedTables } from "./report-detailed-tables";
import { ReportHeatmap } from "./report-heatmap";
import { ReportMemberTable } from "./report-member-table";
import { ReportStressFactors } from "./report-stress-factors";
import { ReportSummary } from "./report-summary";
import {
  type StressProjectChartImages,
  StressProjectReportPDF,
  type StressProjectReportPDFData,
} from "./stress-project-report-pdf";

interface StressProjectReportProps {
  stressProjectData: StressProjectResponse;
  reportApiData: StressProjectReportResponse;
}

const StressProjectReport = ({
  stressProjectData,
  reportApiData,
}: StressProjectReportProps) => {
  const navigate = useNavigate();
  const [isGeneratingPdf, setIsGeneratingPdf] = useState(false);

  const summaryRef = useRef<HTMLDivElement>(null);
  const analysisCardsRef = useRef<HTMLDivElement>(null);
  const heatmapRef = useRef<HTMLDivElement>(null);
  const detailedTablesRef = useRef<HTMLDivElement>(null);

  const stressProject = stressProjectData?.data;
  const apiReport = reportApiData?.data;

  if (!stressProject || !apiReport) {
    return null;
  }

  // Map backend data to frontend structure
  const report: StressReportData = {
    stress_check_period: {
      id: stressProject.id,
      title: stressProject.title,
      year: stressProject.year,
      start_date: apiReport.start_date || stressProject.start_date,
      end_date: apiReport.end_date || stressProject.end_date,
      status: stressProject.status,
    },
    summary: {
      total_employees: apiReport.total_employees || 0,
      total_respondents: apiReport.participated_employees || 0,
      response_rate: apiReport.participation_rate || 0,
      not_completed: apiReport.not_completed_employees || 0,
    },
    stress_factors: {
      psychological_burden_quantity: {
        current: apiReport.workload_score || 0,
        previous: apiReport.previous_workload_score || null,
        national: 8.7,
      },
      psychological_burden_quality: {
        current: apiReport.workload_score || 0,
        previous: apiReport.previous_workload_score || null,
        national: 8.1,
      },
      physical_burden: {
        current: apiReport.workload_score || 0,
        previous: apiReport.previous_workload_score || null,
        national: 7.8,
      },
      interpersonal_stress: {
        current: apiReport.workload_score || 0,
        previous: apiReport.previous_workload_score || null,
        national: 7.2,
      },
      workplace_environment: {
        current: apiReport.workload_score || 0,
        previous: apiReport.previous_workload_score || null,
        national: 7.4,
      },
      job_control: {
        current: apiReport.control_score || 0,
        previous: apiReport.previous_control_score || null,
        national: 7.9,
      },
      skill_utilization: {
        current: apiReport.control_score || 0,
        previous: apiReport.previous_control_score || null,
        national: 8.0,
      },
      job_suitability: {
        current: apiReport.control_score || 0,
        previous: apiReport.previous_control_score || null,
        national: 8.3,
      },
      work_motivation: {
        current: apiReport.control_score || 0,
        previous: apiReport.previous_control_score || null,
        national: 8.5,
      },
    },
    support: {
      supervisor_support: {
        current: apiReport.supervisor_support_score || 0,
        previous: apiReport.previous_supervisor_support_score || null,
        national: 7.5,
      },
      colleague_support: {
        current: apiReport.coworker_support_score || 0,
        previous: apiReport.previous_coworker_support_score || null,
        national: 8.1,
      },
      family_support: {
        current: apiReport.supervisor_support_score || 0,
        previous: apiReport.previous_supervisor_support_score || null,
        national: 8.3,
      },
      supervisor_relationship: {
        current: apiReport.supervisor_support_score || 0,
        previous: apiReport.previous_supervisor_support_score || null,
        national: 7.7,
      },
      colleague_relationship: {
        current: apiReport.coworker_support_score || 0,
        previous: apiReport.previous_coworker_support_score || null,
        national: 8.2,
      },
      family_relationship: {
        current: apiReport.coworker_support_score || 0,
        previous: apiReport.previous_coworker_support_score || null,
        national: 8.4,
      },
    },
    satisfaction: {
      job_satisfaction: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 8.0,
      },
      life_satisfaction: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 8.1,
      },
      consultation_availability: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 8.3,
      },
      personal_respect: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 7.8,
      },
    },
    stress_response: {
      vitality: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 8.5,
      },
      irritability: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 6.8,
      },
      fatigue: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 7.0,
      },
      anxiety: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 7.2,
      },
      depression: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 6.8,
      },
      physical_complaints: {
        current: apiReport.reaction_to_stress_score || 0,
        previous: null,
        national: 7.3,
      },
    },
    health_risk: {
      total_score: apiReport.comprehensive_risk_score || 100,
      evaluation:
        (apiReport.comprehensive_risk_score || 100) < 90
          ? "good"
          : (apiReport.comprehensive_risk_score || 100) <= 110
            ? "caution"
            : "warning",
      previous_score: apiReport.previous_comprehensive_risk_score ?? null,
      national_average: 100,
    },
    high_stress: {
      ratio: apiReport.high_stress_ratio || 0,
      evaluation: (apiReport.high_stress_ratio || 0) > 10 ? "caution" : "good",
      previous_ratio: apiReport.previous_high_stress_ratio ?? null,
      national_average: 10.0,
    },
    scatter_data: {
      psychological_workload: {
        current: {
          x: apiReport.workload_score || 0,
          y: apiReport.control_score || 0,
        },
        previous:
          apiReport.previous_workload_score && apiReport.previous_control_score
            ? {
                x: apiReport.previous_workload_score,
                y: apiReport.previous_control_score,
              }
            : null,
        national: { x: 8.7, y: 7.9 },
        quadrants: [],
        health_risk: apiReport.calculated_risk_workload,
        previous_health_risk: apiReport.previous_calculated_risk_workload,
        national_health_risk: 100,
      },
      support: {
        current: {
          x: apiReport.supervisor_support_score || 0,
          y: apiReport.coworker_support_score || 0,
        },
        previous:
          apiReport.previous_supervisor_support_score &&
          apiReport.previous_coworker_support_score
            ? {
                x: apiReport.previous_supervisor_support_score,
                y: apiReport.previous_coworker_support_score,
              }
            : null,
        national: { x: 7.5, y: 8.1 },
        quadrants: [],
        health_risk: apiReport.calculated_risk_support,
        previous_health_risk: apiReport.previous_calculated_risk_support,
        national_health_risk: 99,
      },
    },
    start_date: undefined,
    end_date: undefined,
    total_employees: 0,
    participated_employees: 0,
    participation_rate: 0,
    workload_score: 0,
    previous_workload_score: null,
    control_score: 0,
    previous_control_score: null,
    supervisor_support_score: 0,
    previous_supervisor_support_score: null,
    coworker_support_score: 0,
    previous_coworker_support_score: null,
    reaction_to_stress_score: 0,
    comprehensive_risk_score: 0,
    high_stress_ratio: 0,
  };

  const handleDownloadPdf = async () => {
    if (isGeneratingPdf) return;

    try {
      setIsGeneratingPdf(true);

      if ("fonts" in document) {
        await document.fonts.ready;
      }

      const captureOptions = {
        backgroundColor: "#ffffff",
        cacheBust: true,
        pixelRatio: 2,
      };

      const chartImages: StressProjectChartImages = {};

      if (summaryRef.current) {
        chartImages.summary = await toPng(summaryRef.current, captureOptions);
      }
      if (analysisCardsRef.current) {
        chartImages.analysisCards = await toPng(
          analysisCardsRef.current,
          captureOptions
        );
      }
      if (heatmapRef.current) {
        chartImages.heatmap = await toPng(heatmapRef.current, captureOptions);
      }
      if (detailedTablesRef.current) {
        chartImages.detailedTables = await toPng(
          detailedTablesRef.current,
          captureOptions
        );
      }

      const pdfTitle = `集団分析レポート_${stressProject.title}_${stressProject.year}`;

      const pdfData: StressProjectReportPDFData = {
        title: pdfTitle,
        report: report,
        reportComments: apiReport.report_comments ?? {},
      };

      const blob = await pdf(
        <StressProjectReportPDF data={pdfData} chartImages={chartImages} />
      ).toBlob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${pdfTitle}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (error) {
      console.error("Failed to generate PDF:", error);
      toast.error("Failed to generate PDF");
    } finally {
      setIsGeneratingPdf(false);
    }
  };

  return (
    <div className="flex w-full flex-col items-center pb-20">
      <div className="flex w-full max-w-[960px] flex-col gap-4">
        {/* Back Button */}
        <div className="flex w-full items-center gap-1 rounded-[4px] pb-2 pr-1 pt-10">
          <BackButton />
        </div>

        <div className="flex w-full flex-col gap-8 bg-white px-6">
          {/* Page Title */}
          <div className="flex h-[92px] flex-col items-start justify-center gap-4 pb-4 pt-10">
            <div className="inline-flex w-full items-end justify-between">
              <h1 className="font-bold text-[30px] leading-normal">
                ストレスチェック集団分析レポート
              </h1>
              <a
                href="#"
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-2 text-[16px] leading-normal underline"
              >
                <span>集団分析レポートについて</span>
                <ExternalIcon size={16} />
              </a>
            </div>
          </div>

          {/* Report Summary Section */}
          <div ref={summaryRef}>
            <ReportSummary report={report} stressProject={stressProject} />
          </div>

          {/* Description Section */}
          <ReportDescription />

          {/* Member Table */}
          <ReportMemberTable
            startDate={stressProject?.start_date}
            endDate={stressProject?.end_date}
            summary={report.summary}
          />

          {/* Stress Factors Section */}
          <ReportStressFactors
            reportComments={apiReport.report_comments ?? {}}
          />

          {/* Analysis Cards Section */}
          <div ref={analysisCardsRef}>
            <ReportAnalysisCards
              stressFactors={report.stress_factors}
              support={report.support}
            />
          </div>

          {/* Heatmap Section */}
          <div ref={heatmapRef}>
            <ReportHeatmap
              scatterData={report.scatter_data}
              stressFactors={report.stress_factors}
              support={report.support}
              healthRisk={report.health_risk}
            />
          </div>

          {/* Detailed Tables Section */}
          <div ref={detailedTablesRef}>
            <ReportDetailedTables
              scaleDistributions={apiReport.scale_distributions ?? []}
            />
          </div>

          {/* Footer Note */}
          <p className="leading-[22px]">
            <span className="font-bold text-[12px]">※</span>
            <span className="text-[12px]" style={{ fontWeight: 350 }}>
              本分析は対象者をすべて男性と仮定し、全国平均との比較を行っております。そのため、女性の比率が高い集団においては、健康リスクが実際よりもやや高く算出される傾向があります。
            </span>
          </p>

          {/* Action Buttons */}
          <div className="inline-flex items-center justify-center gap-[10px] pt-4">
            <Button
              variant="outline"
              className="w-[300px] rounded-[20px] border-2 border-primary-light px-4 py-2 text-[14px] font-bold leading-[20px]"
              onClick={handleDownloadPdf}
              disabled={isGeneratingPdf}
            >
              {isGeneratingPdf && <LoaderCircle />}
              {isGeneratingPdf ? "PDF生成中..." : "PDFダウンロード"}
            </Button>
            <Button
              variant="outline"
              className="w-[300px] rounded-[20px] border-2 border-primary-light px-4 py-2 text-[14px] font-bold leading-[20px]"
              onClick={() => navigate({ to: RouteConfig.ADMIN_DASHBOARD.path })}
            >
              ダッシュボードへ戻る
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default StressProjectReport;
