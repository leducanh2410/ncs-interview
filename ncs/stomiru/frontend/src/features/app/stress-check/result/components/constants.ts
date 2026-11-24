import { t } from "@/locales/i18n";

const SCALE_LABEL_KEYS: Record<number, string> = {
  1: "stressCheckResultPage-staff.psychologicalBurdenQuantity",
  2: "stressCheckResultPage-staff.psychologicalBurdenQuality",
  3: "stressCheckResultPage-staff.physicalBurden",
  4: "stressCheckResultPage-staff.interpersonalStress",
  5: "stressCheckResultPage-staff.workEnvironmentStress",
  6: "stressCheckResultPage-staff.jobControl",
  7: "stressCheckResultPage-staff.skillUtilization",
  8: "stressCheckResultPage-staff.jobSuitability",
  9: "stressCheckResultPage-staff.jobSatisfaction",
  10: "stressCheckResultPage-staff.vigor",
  11: "stressCheckResultPage-staff.irritability",
  12: "stressCheckResultPage-staff.fatigue",
  13: "stressCheckResultPage-staff.anxiety",
  14: "stressCheckResultPage-staff.depression",
  15: "stressCheckResultPage-staff.physicalComplaints",
  16: "stressCheckResultPage-staff.supervisorSupport",
  17: "stressCheckResultPage-staff.colleagueSupport",
  18: "stressCheckResultPage-staff.familySupport",
  19: "stressCheckResultPage-staff.workLifeSatisfaction",
};

const SCALE_SHORT_LABEL_KEYS: Record<number, string> = {
  1: "stressCheckResultPage-staff.shortPsychologicalBurdenQuantity",
  2: "stressCheckResultPage-staff.shortPsychologicalBurdenQuality",
  3: "stressCheckResultPage-staff.shortPhysicalBurden",
  4: "stressCheckResultPage-staff.shortInterpersonalStress",
  5: "stressCheckResultPage-staff.shortWorkEnvironmentStress",
  6: "stressCheckResultPage-staff.shortJobControl",
  7: "stressCheckResultPage-staff.shortSkillUtilization",
  8: "stressCheckResultPage-staff.shortJobSuitability",
  9: "stressCheckResultPage-staff.shortJobSatisfaction",
  10: "stressCheckResultPage-staff.shortVigor",
  11: "stressCheckResultPage-staff.shortIrritability",
  12: "stressCheckResultPage-staff.shortFatigue",
  13: "stressCheckResultPage-staff.shortAnxiety",
  14: "stressCheckResultPage-staff.shortDepression",
  15: "stressCheckResultPage-staff.shortPhysicalComplaints",
  16: "stressCheckResultPage-staff.shortSupervisorSupport",
  17: "stressCheckResultPage-staff.shortColleagueSupport",
  18: "stressCheckResultPage-staff.shortFamilySupport",
  19: "stressCheckResultPage-staff.shortWorkLifeSatisfaction",
};

export function getScaleLabel(scaleId: number): string | undefined {
  const key = SCALE_LABEL_KEYS[scaleId];
  return key ? t(key) : undefined;
}

export function getScaleShortLabel(scaleId: number): string | undefined {
  const key = SCALE_SHORT_LABEL_KEYS[scaleId];
  return key ? t(key) : undefined;
}

export const CATEGORY_A_SCALE_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 9];
export const CATEGORY_B_SCALE_IDS = [10, 11, 12, 13, 14, 15];
export const CATEGORY_C_SCALE_IDS = [16, 17, 18, 19];

export const MAX_SCORE_PER_SCALE = 4;
