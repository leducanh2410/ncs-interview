import type { RefObject } from "react";
import { CategoryRadarChart } from "./category-radar-chart";
import { ChartLegend } from "./chart-legend";
import { ScoreTable } from "./score-table";
import { SectionTitle } from "./section-title";
import type { ExamResultComment, ScaleItem } from "./types";

export function CategorySection({
  title,
  description,
  items,
  comments,
  sectionColor = "#15ca42",
  chartRef,
}: {
  title: string;
  description: string;
  items: ScaleItem[];
  comments: ExamResultComment[];
  sectionColor?: string;
  chartRef?: RefObject<HTMLDivElement | null>;
}) {
  if (items.length === 0) return null;

  return (
    <section className="flex w-full flex-col gap-4">
      <SectionTitle color={sectionColor}>{title}</SectionTitle>

      <div className="inline-flex w-full items-center justify-center gap-4 px-6">
        <p className="flex-1 text-[18px] leading-[29px] text-black" style={{ fontWeight: 350 }}>
          {description}
        </p>
      </div>

      <div className="inline-flex w-full items-start gap-6">
        <div
          ref={chartRef}
          className="inline-flex flex-col items-start gap-4 bg-white pb-6"
        >
          <CategoryRadarChart items={items} />
          <ChartLegend />
        </div>
        <div className="inline-flex flex-1 flex-col px-4">
          <ScoreTable items={items} />
        </div>
      </div>

      {comments.length > 0 && (
        <div className="inline-flex w-full items-center justify-center gap-4 px-6">
          <p
            className="flex-1 whitespace-pre-line text-[18px] leading-[29px] text-black"
            style={{ fontWeight: 350 }}
          >
            {comments.map((comment) => comment.content).join("\n\n")}
          </p>
        </div>
      )}
    </section>
  );
}
