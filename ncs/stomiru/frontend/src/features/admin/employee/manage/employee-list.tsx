import { useMemo } from "react";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import { DataTable } from "@/components/data-table/data-table";
import { DataTableToolbar } from "@/components/data-table/data-table-toolbar";
import AddIcon from "@/components/icons/add-icon";
import { Button } from "@/components/ui/button";
import { useGetEmployeesQuery } from "@/features/admin/employee/employee.api";
import { getTableColumns } from "./employee-columns";
import { EmployeeExportCsv } from "./employee-export-csv";
import { EmployeeImport } from "./employee-import";
import { useDataTable } from "@/hooks/use-data-table";
import type { ParsedUrlQuery } from "@/helpers/api.helper";
import BackButton from "@/components/custom/back-button";
import { cn } from "@/lib/utils";
import CommonHelper from "@/helpers/common.helper";

interface EmployeeListProps {
  searchParams: ParsedUrlQuery;
  shortCut?: boolean;
  companyId?: number;
}

const EmployeeList = ({ searchParams, shortCut, companyId }: EmployeeListProps) => {
  const navigate = useNavigate();

  const { data: pageData, isFetching } = useGetEmployeesQuery(searchParams);

  const columns = useMemo(() => getTableColumns(shortCut), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  const goToCreate = () => {
    navigate({
      to: shortCut
        ? CommonHelper.replaceUrlParam(
            RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE_CREATE.path,
            { companyId: companyId ?? 0 }
          )
        : RouteConfig.ADMIN_EMPLOYEE_CREATE.route.to,
    });
  };

  return (
    <div className={cn("flex flex-col gap-6 max-w-[992px] px-4 w-full mx-auto", shortCut && "")}>
      {shortCut && (
        <div className="pt-10 pb-2">
          <BackButton />
        </div>
      )}
      <div className="pt-10 pb-2 flex items-center justify-between">
        <h3 className="font-bold text-[24px]">{"従業員一覧"}</h3>
        <div className="flex gap-2">
          <DataTableToolbar table={table} />
          {true && (
            <>
              <EmployeeExportCsv companyId={companyId} />
              <EmployeeImport companyId={companyId} />
              <Button className="min-w-30" onClick={goToCreate}>
                <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
                <span>{"従業員登録"}</span>
              </Button>
            </>
          )}
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

export default EmployeeList;
