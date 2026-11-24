interface ReportStressFactorsProps {
  reportComments: Record<string, string>;
}

const SECTION_ORDER = [
  "overview_health_risk",
  "matrix_analysis_workload",
  "matrix_analysis_support",
  "detailed_factors",
  "recommendation_action",
] as const;

export const ReportStressFactors = ({
  reportComments,
}: ReportStressFactorsProps) => {
  const comments = SECTION_ORDER.map((key) => reportComments[key]).filter(
    Boolean
  );

  return (
    <div className="flex flex-col gap-4 pt-6">
      <div className="flex items-start gap-4">
        <div className="bg-[#15CA42] h-[22px] shrink-0 w-2" />
        <h2 className="font-bold text-[18px] leading-normal">今回の総評</h2>
      </div>
      {comments.length > 0 && (
        <div
          className="inline-flex items-center justify-center gap-4 px-6"
          style={{
            fontWeight: 350,
            fontSize: "18px",
            lineHeight: "29px",
          }}
        >
          <p className="flex-1 whitespace-pre-line">{comments.join("\n\n")}</p>
        </div>
      )}
    </div>
  );
};
