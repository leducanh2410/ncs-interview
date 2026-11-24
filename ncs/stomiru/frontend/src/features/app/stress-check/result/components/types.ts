export interface ScaleItem {
  scaleId: number;
  label: string;
  shortLabel: string;
  score: number;
  previousScore?: number;
  maxScore: number;
  level: "low" | "medium" | "high";
}

export interface ExamResultComment {
  scale_id: number;
  scale_name: string;
  level: "low" | "medium" | "high";
  content: string;
}
