import { useMemo } from "react";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import { DataTable } from "@/components/data-table/data-table";
import { DataTableToolbar } from "@/components/data-table/data-table-toolbar";
import AddIcon from "@/components/icons/add-icon";
import { Button } from "@/components/ui/button";
import { useGetCompaniesQuery } from "@/features/super-admin/company/company.api";
import { getTableColumns } from "./company-table-columns";
import { useDataTable } from "@/hooks/use-data-table";
import { Route } from "@/routes/_authenticated/super-admin/companies";

export const CompanyList = () => {
  const navigate = useNavigate();
  const searchParams = { page_size: 10, ...Route.useSearch() };

  const { data: pageData, isFetching } = useGetCompaniesQuery(searchParams);

  const columns = useMemo(() => getTableColumns(), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  return (
    <>
      <div className="px-8 pt-10 pb-2 flex items-center justify-between">
        <h3 className="font-bold text-[24px]">{"法人管理"}</h3>
        <div className="flex gap-2">
          <DataTableToolbar table={table} />
          <Button
            className="min-w-30"
            onClick={() =>
              navigate({ to: RouteConfig.SUPER_ADMIN_COMPANY_CREATE.route.to })
            }
          >
            <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
            <span>{"法人登録"}</span>
          </Button>
        </div>
      </div>
      <div className="px-8">
        <DataTable isLoading={isFetching} table={table} />
      </div>
    </>
  );
};
