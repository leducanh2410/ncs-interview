import { useMemo } from "react";
import { DataTable } from "@/components/data-table/data-table";
import { DataTableToolbar } from "@/components/data-table/data-table-toolbar";
import { useDataTable } from "@/hooks/use-data-table";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import { getTableColumns } from "./examinee-columns";
import { useGetExamineesQuery } from "../examinee.api";
import { ExamineeExportCsv } from "./examinee-export-csv";
import BackButton from "@/components/custom/back-button";

interface ExamineeListProps {
  searchParams: ParsedUrlQuery;
  shortCut?: boolean;
  stressProjectId: number;
}

const ExamineeList = ({ searchParams, shortCut, stressProjectId }: ExamineeListProps) => {
  const { data: pageData, isFetching } = useGetExamineesQuery(stressProjectId, searchParams);

  const columns = useMemo(() => getTableColumns(stressProjectId, shortCut), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  return (
    <div className="flex flex-col gap-6 max-w-[992px] px-4 w-full mx-auto">
      {shortCut && (
        <div className="pt-10 pb-2">
          <BackButton />
        </div>
      )}
      <div className="pt-10 pb-2 flex items-center justify-between">
        <h3 className="font-bold text-[24px]">{"受検者一覧"}</h3>
        <div className="flex gap-2">
          <DataTableToolbar table={table} />
          <ExamineeExportCsv stressProjectId={stressProjectId} />
        </div>
      </div>
      <div className="">
        <DataTable
          isLoading={isFetching}
          table={table}
          emptyClassName="py-20"
        />
      </div>
    </div>
  );
};

export default ExamineeList;
