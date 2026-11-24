import { useMemo } from "react";
import { useGetStressCheckHistoryQuery } from "../stress-check.api";
import { useAtomValue } from "jotai";
import { authUserAtom } from "@/global.state";
import RoleHelper from "@/helpers/role.helper";
import { DataTable } from "@/components/data-table/data-table";
import { getTableColumns } from "./stress-check-history-columns";
import { useDataTable } from "@/hooks/use-data-table";
import { useTranslation } from "@/hooks/use-translation";
import { Route } from "@/routes/_authenticated/app/stress-check";

const StressCheckHistory = () => {
  const { t } = useTranslation();
  const searchParams = { page_size: 3, ...Route.useSearch() };

  const authUser = useAtomValue(authUserAtom);
  const isStaffManager = RoleHelper.isStaffManager(authUser?.roles || []);

  const { data: pageData, isFetching } =
    useGetStressCheckHistoryQuery(searchParams);

  const columns = useMemo(() => getTableColumns(t, isStaffManager), [t]);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex gap-4 items-center">
        <span className="w-2 h-[22px] bg-primary-light" />
        <span className="font-bold text-[18px] leading-[22px]">
          {t("stressCheckList-staff.history")}
        </span>
      </div>
      <div className="px-6">
        <DataTable
          isLoading={isFetching}
          table={table}
          emptyMessage={t("stressCheckList-staff.noData")}
        />
      </div>
    </div>
  );
};

export default StressCheckHistory;
