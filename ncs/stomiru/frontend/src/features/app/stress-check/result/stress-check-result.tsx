import { useMemo, useRef, useState } from "react";
import { pdf } from "@react-pdf/renderer";
import { toPng } from "html-to-image";
import type { ExamResultData, RadarScore } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import BackButton from "@/components/custom/back-button";
import { LoaderCircle } from "@/components/custom/loader";
import { Button } from "@/components/ui/button";
import {
  CATEGORY_A_SCALE_IDS,
  CATEGORY_B_SCALE_IDS,
  CATEGORY_C_SCALE_IDS,
  CategoryScoreBars,
  CategorySection,
  type ExamResultComment,
  InfoBox,
  MAX_SCORE_PER_SCALE,
  type ScaleItem,
  ScoreGauge,
  SectionTitle,
  getScaleLabel,
  getScaleShortLabel,
  getStressLevelColor,
  mapStressLevelLabel,
} from "./components";
import {
  type ChartImages,
  StressCheckResultPDF,
  type StressCheckResultPDFData,
} from "./stress-check-result-pdf";
import { useTranslation } from "@/hooks/use-translation";

const CATEGORY_MAX_SCORES = {
  a: 43,
  b: 30,
  c: 15,
  total: 88,
} as const;

function useDisplayData(
  resultData: ExamResultData | null,
  t: (key: string) => string
) {
  return useMemo(() => {
    if (!resultData?.exam_result) return null;

    const {
      exam_result,
      radar_scores,
      previous_radar_scores,
      category_a_score,
      category_b_score,
      category_c_score,
      comments,
    } = resultData;

    const adviceMap: Record<number, "low" | "medium" | "high"> = {};
    (exam_result.advice_codes ?? []).forEach((ac) => {
      adviceMap[ac.scale_id] = ac.level as "low" | "medium" | "high";
    });

    const scoreMap: Record<number, RadarScore> = {};
    radar_scores.forEach((rs) => {
      scoreMap[rs.scale_id] = rs;
    });

    const previousScoreMap: Record<number, RadarScore> = {};
    if (previous_radar_scores) {
      previous_radar_scores.forEach((rs) => {
        previousScoreMap[rs.scale_id] = rs;
      });
    }

    const buildItems = (scaleIds: number[]): ScaleItem[] =>
      scaleIds
        .filter((id) => scoreMap[id])
        .map((id) => ({
          scaleId: id,
          label: getScaleLabel(id) || `Scale ${id}`,
          shortLabel: getScaleShortLabel(id) || getScaleLabel(id) || `${id}`,
          score: scoreMap[id].score ?? 0,
          previousScore: previousScoreMap[id]?.score ?? undefined,
          maxScore: MAX_SCORE_PER_SCALE,
          level: adviceMap[id] || "low",
        }));

    const catAItems = buildItems(CATEGORY_A_SCALE_IDS);
    const catBItems = buildItems(CATEGORY_B_SCALE_IDS);
    const catCItems = buildItems(CATEGORY_C_SCALE_IDS);

    const catAComments = (comments as ExamResultComment[]).filter((c) =>
      CATEGORY_A_SCALE_IDS.includes(c.scale_id)
    );
    const catBComments = (comments as ExamResultComment[]).filter((c) =>
      CATEGORY_B_SCALE_IDS.includes(c.scale_id)
    );
    const catCComments = (comments as ExamResultComment[]).filter((c) =>
      CATEGORY_C_SCALE_IDS.includes(c.scale_id)
    );

    const totalScore = category_a_score + category_b_score + category_c_score;
    const hasPreviousScores = Boolean(previous_radar_scores?.length);
    const getPreviousCategoryScore = (scaleIds: number[]) => {
      if (!hasPreviousScores) {
        return undefined;
      }

      return scaleIds.reduce(
        (sum, scaleId) => sum + (previousScoreMap[scaleId]?.score ?? 0),
        0
      );
    };

    const previousCategoryAScore = getPreviousCategoryScore(CATEGORY_A_SCALE_IDS);
    const previousCategoryBScore = getPreviousCategoryScore(CATEGORY_B_SCALE_IDS);
    const previousCategoryCScore = getPreviousCategoryScore(CATEGORY_C_SCALE_IDS);
    const previousTotalScore = hasPreviousScores
      ? (previousCategoryAScore ?? 0) +
        (previousCategoryBScore ?? 0) +
        (previousCategoryCScore ?? 0)
      : undefined;

    const maxA = CATEGORY_MAX_SCORES.a;
    const maxB = CATEGORY_MAX_SCORES.b;
    const maxC = CATEGORY_MAX_SCORES.c;
    const maxTotal = CATEGORY_MAX_SCORES.total;

    const stressLevel = exam_result.stress_level ?? "medium";
    const stressLevelLabel = mapStressLevelLabel(stressLevel);
    const isHighStress = stressLevel === "high";

    const stressDescriptionMap: Record<string, string> = {
      low: t("stressCheckResultPage-staff.stressDescriptionLow"),
      medium: t("stressCheckResultPage-staff.stressDescriptionMedium"),
      high: t("stressCheckResultPage-staff.stressDescriptionHigh"),
    };
    const stressDescriptionText =
      stressDescriptionMap[stressLevel] || stressDescriptionMap.medium;

    const catADescription = isHighStress
      ? t("stressCheckResultPage-staff.catADescriptionHighStress")
      : t("stressCheckResultPage-staff.catADescriptionDefault");
    const catBDescription = isHighStress
      ? t("stressCheckResultPage-staff.catBDescriptionHighStress")
      : t("stressCheckResultPage-staff.catBDescriptionDefault");
    const catCDescription = t("stressCheckResultPage-staff.catCDescription");

    return {
      exam_result,
      category_a_score,
      category_b_score,
      category_c_score,
      previousCategoryAScore,
      previousCategoryBScore,
      previousCategoryCScore,
      totalScore,
      previousTotalScore,
      maxTotal,
      maxA,
      maxB,
      maxC,
      catAItems,
      catBItems,
      catCItems,
      catAComments,
      catBComments,
      catCComments,
      isHighStress,
      stressLevel,
      stressLevelLabel,
      stressLevelColor: getStressLevelColor(stressLevel),
      stressDescriptionText,
      catADescription,
      catBDescription,
      catCDescription,
    };
  }, [resultData, t]);
}

interface StressCheckResultProps {
  shortcut?: boolean;
  data: ExamResultData | null;
  id?: string | number;
}

const StressCheckResult = ({ shortcut, data, id }: StressCheckResultProps) => {
  const { t, tVar } = useTranslation();
  const displayData = useDisplayData(data, t);

  const [isGeneratingPdf, setIsGeneratingPdf] = useState(false);
  const stressGaugeRef = useRef<HTMLDivElement>(null);
  const radarChartARef = useRef<HTMLDivElement>(null);
  const radarChartBRef = useRef<HTMLDivElement>(null);
  const radarChartCRef = useRef<HTMLDivElement>(null);

  const handleDownloadPdf = async () => {
    if (!displayData || isGeneratingPdf) return;
    try {
      setIsGeneratingPdf(true);

      const captureOptions = { cacheBust: true, pixelRatio: 2 };
      const chartImages: ChartImages = {};

      if (stressGaugeRef.current) {
        chartImages.stressGauge = await toPng(
          stressGaugeRef.current,
          captureOptions
        );
      }
      if (radarChartARef.current) {
        chartImages.radarChartA = await toPng(
          radarChartARef.current,
          captureOptions
        );
      }
      if (radarChartBRef.current) {
        chartImages.radarChartB = await toPng(
          radarChartBRef.current,
          captureOptions
        );
      }
      if (radarChartCRef.current) {
        chartImages.radarChartC = await toPng(
          radarChartCRef.current,
          captureOptions
        );
      }

      const isHighStress = displayData.stressLevel === "high";
      const introText = isHighStress
        ? (tVar("stressCheckResultPage-staff.introHighStress", {
            label: `「${displayData.stressLevelLabel}」`,
          }) as string)
        : (tVar("stressCheckResultPage-staff.introLowStress", {
            label: `「${displayData.stressLevelLabel}」`,
          }) as string);

      const yellowBoxContent = isHighStress
        ? t("stressCheckResultPage-staff.infoBoxContentHighStress")
        : t("stressCheckResultPage-staff.infoBoxContentLowStress");

      const pdfData: StressCheckResultPDFData = {
        ...displayData,
        isHighStress,
        introText,
        yellowBoxContent,
      };

      const blob = await pdf(
        <StressCheckResultPDF data={pdfData} chartImages={chartImages} />
      ).toBlob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `${t("stressCheckResultPage-staff.pdfFileNamePrefix")}${id != null ? `_${id}` : ""}.pdf`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    } catch (error) {
      console.error("Failed to generate PDF:", error);
    } finally {
      setIsGeneratingPdf(false);
    }
  };

  if (!displayData) {
    return (
      <div className="bg-white min-h-screen flex items-center justify-center px-6">
        <div className="flex flex-col items-center gap-6 max-w-[480px] text-center">
          <div className="w-[80px] h-[80px] rounded-full bg-red-50 flex items-center justify-center">
            <svg
              width="40"
              height="40"
              viewBox="0 0 24 24"
              fill="none"
              stroke="#ef4444"
              strokeWidth="2.5"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="12" cy="12" r="10" />
              <line x1="15" y1="9" x2="9" y2="15" />
              <line x1="9" y1="9" x2="15" y2="15" />
            </svg>
          </div>
          <h2 className="font-bold text-[24px] text-black">
            {t("stressCheckResultPage-staff.errorOccurred")}
          </h2>
          <p className="text-[16px] text-[#7c838c]">
            {t("stressCheckResultPage-staff.dataNotFound")}
          </p>
          <BackButton
            href={RouteConfig.APP_STRESS_CHECK.path}
            label={t("stressCheckResultPage-staff.goHome")}
          />
        </div>
      </div>
    );
  }

  const {
    stressLevel,
    stressLevelLabel,
    stressLevelColor,
    totalScore,
    previousTotalScore,
    maxTotal,
    category_a_score,
    category_b_score,
    category_c_score,
    previousCategoryAScore,
    previousCategoryBScore,
    previousCategoryCScore,
    maxA,
    maxB,
    maxC,
    catAItems,
    catBItems,
    catCItems,
    catAComments,
    catBComments,
    catCComments,
    stressDescriptionText,
    catADescription,
    catBDescription,
    catCDescription,
  } = displayData;

  const isHighStress = stressLevel === "high";

  const introText = isHighStress
    ? tVar(
        "stressCheckResultPage-staff.introHighStress",
        { label: stressLevelLabel },
        {
          label: (value) => (
            <span className="font-bold" style={{ color: stressLevelColor }}>
              「{value}」
            </span>
          ),
        }
      )
    : tVar(
        "stressCheckResultPage-staff.introLowStress",
        { label: stressLevelLabel },
        {
          label: (value) => (
            <span className="font-bold" style={{ color: stressLevelColor }}>
              「{value}」
            </span>
          ),
        }
      );

  const infoBoxTitle = isHighStress
    ? t("stressCheckResultPage-staff.professionalSupport")
    : t("stressCheckResultPage-staff.currentCondition");
  const infoBoxContent = isHighStress
    ? t("stressCheckResultPage-staff.infoBoxContentHighStress")
    : t("stressCheckResultPage-staff.infoBoxContentLowStress");
  const infoBoxButtonText = isHighStress
    ? t("stressCheckResultPage-staff.bookConsultation")
    : t("stressCheckResultPage-staff.selfCareTips");

  return (
    <div className="flex w-full flex-col items-center gap-4 bg-white pb-20">
      <div className="flex w-full max-w-[960px] items-center gap-1 rounded-[4px] pb-2 pr-1 pt-10">
        <BackButton label={t("stressCheckResultPage-staff.back")} />
      </div>

      <div className="flex w-full max-w-[960px] flex-col gap-12 bg-white px-6">
        <section className="flex w-full flex-col items-start justify-center gap-4 pb-4 pt-10">
          <div className="flex w-full flex-col gap-4 px-6 pb-10">
            <p className="text-[20px] font-bold leading-normal text-black">
              {t("stressCheckResultPage-staff.thank")}
            </p>
            <h1 className="text-[26px] font-bold leading-[34px] text-black">
              {introText}
            </h1>
          </div>

          <SectionTitle>{t("stressCheckResultPage-staff.stressLevel")}</SectionTitle>

          <div className="w-full px-6">
            <div
              ref={stressGaugeRef}
              className="flex h-[258px] w-full items-start gap-14 p-8"
            >
              <div className="shrink-0">
                <ScoreGauge
                  score={totalScore}
                  maxScore={maxTotal}
                  previousScore={previousTotalScore}
                  color={stressLevelColor}
                />
              </div>

              <CategoryScoreBars
                categoryA={category_a_score}
                categoryB={category_b_score}
                categoryC={category_c_score}
                previousCategoryA={previousCategoryAScore}
                previousCategoryB={previousCategoryBScore}
                previousCategoryC={previousCategoryCScore}
                maxA={maxA}
                maxB={maxB}
                maxC={maxC}
              />
            </div>
          </div>

          <div className="inline-flex w-full items-center justify-center gap-4 px-6">
            <p
              className="flex-1 text-[18px] leading-[29px] text-black"
              style={{ fontWeight: 350 }}
            >
              {stressDescriptionText}
            </p>
          </div>

          <InfoBox
            isHighStress={isHighStress}
            title={infoBoxTitle}
            content={infoBoxContent}
            buttonText={infoBoxButtonText}
          />
        </section>

        <CategorySection
          title={t("stressCheckResultPage-staff.stressFactors")}
          description={catADescription}
          items={catAItems}
          comments={catAComments}
          chartRef={radarChartARef}
        />
        <CategorySection
          title={t("stressCheckResultPage-staff.mentalReactions")}
          description={catBDescription}
          items={catBItems}
          comments={catBComments}
          chartRef={radarChartBRef}
        />
        <CategorySection
          title={t("stressCheckResultPage-staff.otherFactors")}
          description={catCDescription}
          items={catCItems}
          comments={catCComments}
          chartRef={radarChartCRef}
        />

        <div className="inline-flex items-center justify-center gap-[10px] pt-4">
          <Button
            variant="outline"
            className="w-[300px] rounded-[20px] border-2 border-primary-light bg-white px-4 py-2 text-[14px] font-bold leading-[20px] text-black"
            onClick={handleDownloadPdf}
            disabled={isGeneratingPdf}
          >
            {isGeneratingPdf && <LoaderCircle />}
            {isGeneratingPdf
              ? t("stressCheckResultPage-staff.generatingPdf")
              : t("stressCheckResultPage-staff.downloadPdf")}
          </Button>
          {!shortcut && (
            <Button
              variant="outline"
              className="w-[300px] rounded-[20px] border-2 border-primary-light bg-white px-4 py-2 text-[14px] font-bold leading-[20px] text-black"
              onClick={() =>
                window.location.assign(RouteConfig.APP_STRESS_CHECK.path)
              }
            >
              {t("stressCheckResultPage-staff.goHome")}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
};

export default StressCheckResult;
