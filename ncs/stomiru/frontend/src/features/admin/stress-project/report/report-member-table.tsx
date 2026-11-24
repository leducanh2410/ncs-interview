import DateTimeHelper from "@/helpers/date-time.helper";

interface ReportMemberTableProps {
  startDate?: string;
  endDate?: string;
  summary: {
    total_employees: number;
    total_respondents: number;
    response_rate: number;
    not_completed?: number;
  };
}

export const ReportMemberTable = ({
  startDate,
  endDate,
  summary,
}: ReportMemberTableProps) => {
  const notCompleted =
    summary.not_completed ??
    summary.total_employees - summary.total_respondents;

  const period =
    startDate && endDate
      ? `${DateTimeHelper.format(startDate, "yyyy/MM/dd")} ～ ${DateTimeHelper.format(endDate, "yyyy/MM/dd")}`
      : "--";

  return (
    <div className="flex flex-col px-4">
      <div className="inline-flex w-full items-center border-t border-[#7C838C] bg-[#F8F8FC] px-2">
        <div className="flex h-[40px] flex-1 items-center gap-2.5 px-[12px] py-[4px]">
          <p className="text-[14px] font-bold leading-normal">実施期間</p>
        </div>
        <div className="flex h-[40px] w-[180px] items-center gap-2.5 px-2 py-[4px]">
          <p className="text-[14px] font-bold leading-normal">対象者数</p>
        </div>
        <div className="flex h-[40px] w-[180px] items-center gap-2.5 px-2 py-[4px]">
          <p className="text-[14px] font-bold leading-normal">受検者数</p>
        </div>
        <div className="flex h-[40px] w-[180px] items-center gap-2.5 px-2 py-[4px]">
          <p className="text-[14px] font-bold leading-normal">未回答数</p>
        </div>
      </div>

      <div className="inline-flex w-full items-center border-b border-[#7C838C] bg-white px-2 py-1">
        <div className="flex flex-1 items-center gap-2.5 p-2">
          <p className="text-[16px] leading-normal">{period}</p>
        </div>
        <div className="flex w-[180px] items-center gap-2.5 p-2">
          <p className="text-[16px] leading-normal">
            {summary.total_employees}名
          </p>
        </div>
        <div className="flex w-[180px] items-center gap-2.5 p-2">
          <p className="text-[16px] leading-normal">
            {summary.total_respondents}名
          </p>
        </div>
        <div className="flex w-[180px] items-center gap-2.5 p-2">
          <p className="text-[16px] leading-normal">{notCompleted}名</p>
        </div>
      </div>
    </div>
  );
};
