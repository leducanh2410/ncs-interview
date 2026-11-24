import { useMemo, useRef, useState } from "react";
import type { ExamResultData, RadarScore } from "@/types/models/stress-check";
import BackButton from "@/components/custom/back-button";
import { Button } from "@/components/ui/button";
import {
  ScoreGauge,
  CategoryScoreBars,
  SectionTitle,
  CategorySection,
  InfoBox,
  CATEGORY_A_SCALE_IDS,
  CATEGORY_B_SCALE_IDS,
  CATEGORY_C_SCALE_IDS,
  MAX_SCORE_PER_SCALE,
  getScaleLabel,
  getScaleShortLabel,
  mapStressLevelLabel,
  getStressLevelColor,
  type ScaleItem,
  type ExamResultComment,
} from "@/features/app/stress-check/result/components";
import { useTranslation } from "@/hooks/use-translation";
import { SelfCareExitWarningModal } from "./self-care-exit-warning-modal";

function useDisplayData(
  resultData: ExamResultData | null,
  t: (key: string) => string
) {
  return useMemo(() => {
    if (!resultData?.exam_result) return null;

    const {
      exam_result,
      radar_scores,
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

    const buildItems = (scaleIds: number[]): ScaleItem[] =>
      scaleIds
        .filter((id) => scoreMap[id])
        .map((id) => ({
          scaleId: id,
          label: getScaleLabel(id) || `Scale ${id}`,
          shortLabel: getScaleShortLabel(id) || getScaleLabel(id) || `${id}`,
          score: scoreMap[id].score ?? 0,
          previousScore: undefined,
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

    const allScores = radar_scores.map((rs) => rs.score ?? 0);
    const maxScoreFromData =
      allScores.length > 0 ? Math.max(...allScores) : MAX_SCORE_PER_SCALE;

    const maxA = catAItems.length * maxScoreFromData;
    const maxB = catBItems.length * maxScoreFromData;
    const maxC = catCItems.length * maxScoreFromData;
    const maxTotal = maxA + maxB + maxC;

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
      category_a_score,
      category_b_score,
      category_c_score,
      totalScore,
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

interface SelfCareResultViewProps {
  resultData: ExamResultData;
  onBack: () => void;
}

export function SelfCareResultView({
  resultData,
  onBack,
}: SelfCareResultViewProps) {
  const { t, tVar } = useTranslation();
  const [showExitWarning, setShowExitWarning] = useState(false);

  const radarChartARef = useRef<HTMLDivElement>(null);
  const radarChartBRef = useRef<HTMLDivElement>(null);
  const radarChartCRef = useRef<HTMLDivElement>(null);

  const displayData = useDisplayData(resultData, t);

  if (!displayData) return null;

  const {
    stressLevel,
    stressLevelLabel,
    stressLevelColor,
    totalScore,
    maxTotal,
    category_a_score,
    category_b_score,
    category_c_score,
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

  const handleBack = () => {
    setShowExitWarning(true);
  };

  const confirmExit = () => {
    setShowExitWarning(false);
    onBack();
  };

  return (
    <div className="bg-white pb-[80px]">
      <SelfCareExitWarningModal
        open={showExitWarning}
        setOpen={setShowExitWarning}
        onConfirmExit={confirmExit}
      />

      <div className="max-w-[992px] px-4 mx-auto px-[40px] pt-[40px]">
        <BackButton
          onClick={handleBack}
          label={t("stressCheckPage-staff.returnHome")}
          className="mb-[24px]"
        />

        <div className="mb-[32px]">
          <p className="text-[20px] text-black font-bold leading-[100%] tracking-[0.5px] mb-[8px]">
            {t("stressCheckResultPage-staff.thank")}
          </p>
          <h1 className="text-[26px] font-bold text-black leading-[34px]">
            {introText}
          </h1>
        </div>

        <section className="mb-[32px]">
          <SectionTitle>{t("stressCheckResultPage-staff.stressLevel")}</SectionTitle>

          <div className="p-[24px]">
            <div className="flex gap-[32px] items-start">
              <div className="flex flex-col items-center shrink-0">
                <ScoreGauge
                  score={totalScore}
                  maxScore={maxTotal}
                  color={stressLevelColor}
                />
              </div>

              <CategoryScoreBars
                categoryA={category_a_score}
                categoryB={category_b_score}
                categoryC={category_c_score}
                maxA={maxA}
                maxB={maxB}
                maxC={maxC}
              />
            </div>

            <p className="text-[18px] font-light text-black mt-[20px]">
              {stressDescriptionText}
            </p>
          </div>
        </section>

        <InfoBox
          isHighStress={isHighStress}
          title={infoBoxTitle}
          content={infoBoxContent}
          buttonText={infoBoxButtonText}
        />

        <CategorySection
          title={t("stressCheckResultPage-staff.stressFactors")}
          description={catADescription}
          items={catAItems}
          comments={catAComments}
          chartRef={radarChartARef}
        />
        <CategorySection
          title={t("selfCareResultPage-staff.mentalChanges")}
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
          sectionColor={isHighStress ? "#f44336" : "#15ca42"}
          chartRef={radarChartCRef}
        />

        <div className="border-t border-gray-200 pt-[24px] mb-[32px]">
          <p className="text-[13px] text-gray-500 leading-[22px]">
            {t("stressCheckResultPage-staff.resultDisclaimer")}
          </p>
        </div>

        <div className="flex items-center justify-center gap-[10px]">
          <Button
            className="w-[240px] h-[40px] rounded-[20px] font-bold text-[14px]"
            onClick={handleBack}
          >
            {t("stressCheckResultPage-staff.goHome")}
          </Button>
        </div>
      </div>
    </div>
  );
}
