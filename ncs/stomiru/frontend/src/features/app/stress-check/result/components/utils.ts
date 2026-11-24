import { t } from "@/locales/i18n";

export function mapStressLevelLabel(level: string): string {
  switch (level) {
    case "low":
      return t("stressCheckResultPage-staff.stressLevelLabelLow");
    case "medium":
      return t("stressCheckResultPage-staff.stressLevelLabelMedium");
    case "high":
      return t("stressCheckResultPage-staff.stressLevelLabelHigh");
    default:
      return t("stressCheckResultPage-staff.stressLevelLabelMedium");
  }
}

export function getStressLevelColor(level: string): string {
  switch (level) {
    case "low":
      return "#15ca42";
    case "medium":
      return "#ffc107";
    case "high":
      return "#f44336";
    default:
      return "#15ca42";
  }
}
