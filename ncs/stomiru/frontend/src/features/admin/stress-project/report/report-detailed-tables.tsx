import type { ScaleDistribution } from "../stress-project.api";
import LikeIcon from "@/components/icons/like-icon";
import TimelineIcon from "@/components/icons/timeline-icon";
import WarehousingIcon from "@/components/icons/warehousing-icon";

interface DistributionRow {
  label: string;
  poor: number;
  somewhat_poor: number;
  average: number;
  somewhat_good: number;
  good: number;
}

interface ReportDetailedTablesProps {
  scaleDistributions: ScaleDistribution[];
}

// Scale IDs grouped by table section
const STRESS_FACTOR_SCALE_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 9];
const SUPPORT_SCALE_IDS = [16, 17, 18, 19];
const STRESS_RESPONSE_SCALE_IDS = [10, 11, 12, 13, 14, 15];

const mapToRows = (
  distributions: ScaleDistribution[],
  scaleIds: number[]
): DistributionRow[] => {
  return scaleIds
    .map((id) => distributions.find((d) => d.scale_id === id))
    .filter((d): d is ScaleDistribution => d != null)
    .map((d) => ({
      label: d.scale_translation_ja,
      poor: parseFloat(d.bad_percentage),
      somewhat_poor: parseFloat(d.slightly_bad_percentage),
      average: parseFloat(d.average_percentage),
      somewhat_good: parseFloat(d.slightly_good_percentage),
      good: parseFloat(d.good_percentage),
    }));
};

const getHighestIndex = (row: DistributionRow): number => {
  const values = [
    row.poor,
    row.somewhat_poor,
    row.average,
    row.somewhat_good,
    row.good,
  ];
  const maxValue = Math.max(...values);
  return values.indexOf(maxValue);
};

const renderTableRows = (rows: DistributionRow[]) => {
  return rows.map((row, index) => {
    const highestIndex = getHighestIndex(row);
    const isEvenRow = index % 2 === 0;
    const cellClass = (colIndex: number) =>
      `w-[100px] flex items-center justify-center p-[8px] ${highestIndex === colIndex ? "bg-[rgba(235,37,61,0.1)]" : ""}`;

    return (
      <div
        key={row.label}
        className={`flex items-center px-[8px] py-[2px] w-full ${isEvenRow ? "bg-white" : "bg-[#F8F8FC]"}`}
      >
        <div className="flex-1 p-[8px]">
          <p className="text-[14px] leading-normal">{row.label}</p>
        </div>
        <div className={cellClass(0)}>
          <p className="text-[14px] leading-normal">{row.poor.toFixed(1)}%</p>
        </div>
        <div className={cellClass(1)}>
          <p className="text-[14px] leading-normal">
            {row.somewhat_poor.toFixed(1)}%
          </p>
        </div>
        <div className={cellClass(2)}>
          <p className="text-[14px] leading-normal">
            {row.average.toFixed(1)}%
          </p>
        </div>
        <div className={cellClass(3)}>
          <p className="text-[14px] leading-normal">
            {row.somewhat_good.toFixed(1)}%
          </p>
        </div>
        <div className={cellClass(4)}>
          <p className="text-[14px] leading-normal">{row.good.toFixed(1)}%</p>
        </div>
      </div>
    );
  });
};

const TableHeader = () => (
  <div className="bg-[#F8F8FC] border-b border-[#EBEBF5] flex h-[40px] items-center px-[8px] rounded-tl-[8px] rounded-tr-[8px]">
    <div className="flex-1 h-[40px] flex items-center pl-[12px] pr-[8px] py-[4px]">
      <p className="font-bold text-[14px] leading-normal">項目</p>
    </div>
    {["不良", "やや不良", "平均的", "やや良好", "良好"].map((label) => (
      <div
        key={label}
        className="w-[100px] h-[40px] flex items-center justify-center pl-[12px] pr-[8px] py-[4px]"
      >
        <p className="font-bold text-[14px] leading-normal">{label}</p>
      </div>
    ))}
  </div>
);

export const ReportDetailedTables = ({
  scaleDistributions,
}: ReportDetailedTablesProps) => {
  const stressFactorRows = mapToRows(
    scaleDistributions,
    STRESS_FACTOR_SCALE_IDS
  );
  const supportRows = mapToRows(scaleDistributions, SUPPORT_SCALE_IDS);
  const stressResponseRows = mapToRows(
    scaleDistributions,
    STRESS_RESPONSE_SCALE_IDS
  );

  return (
    <div className="flex flex-col gap-4 pt-6">
      <div className="flex gap-[16px] items-start">
        <div className="bg-[#15CA42] h-[22px] shrink-0 w-[8px]" />
        <h2 className="font-bold text-[18px] leading-normal">各尺度別の割合</h2>
      </div>

      <div className="flex flex-col gap-4 px-6">
        {/* Stress Factors Table */}
        <div className="flex flex-col gap-4">
          <div className="inline-flex items-center gap-2 pt-6">
            <TimelineIcon size={20} />
            <h3 className="font-medium text-[14px] leading-normal">
              ストレスの原因と考えられる因子
            </h3>
          </div>
          <div className="px-4">
            <div className="rounded-lg overflow-hidden">
              <div className="flex flex-col">
                <TableHeader />
                {renderTableRows(stressFactorRows)}
              </div>
            </div>
          </div>
        </div>

        {/* Support Table */}
        <div className="flex flex-col gap-4">
          <div className="inline-flex items-center gap-2 pt-6">
            <LikeIcon size={20} />
            <h3 className="font-medium text-[14px] leading-normal">
              ストレスによる心身反応
            </h3>
          </div>
          <div className="px-4">
            <div className="rounded-lg overflow-hidden">
              <div className="flex flex-col">
                <TableHeader />
                {renderTableRows(stressResponseRows)}
              </div>
            </div>
          </div>
        </div>

        {/* Stress Response Table */}
        <div className="flex flex-col gap-4">
          <div className="inline-flex items-center gap-2 pt-6">
            <WarehousingIcon size={20} />
            <h3 className="font-medium text-[14px] leading-normal">
              ストレス反応に影響を与える他の因子
            </h3>
          </div>
          <div className="px-4">
            <div className="rounded-lg overflow-hidden">
              <div className="flex flex-col">
                <TableHeader />
                {renderTableRows(supportRows)}
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};
