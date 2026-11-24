import { useMemo } from "react";
import { useGetStressProjectsQuery } from "../stress-project.api";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import { DataTable } from "@/components/data-table/data-table";
import { DataTableToolbar } from "@/components/data-table/data-table-toolbar";
import AddIcon from "@/components/icons/add-icon";
import { Button } from "@/components/ui/button";
import { getTableColumns } from "./stress-project-columns";
import { useDataTable } from "@/hooks/use-data-table";
import { Route } from "@/routes/_authenticated/admin/stress-project/index";
import { authUserAtom } from "@/global.state";
import { useAtomValue } from "jotai";
import RoleHelper from "@/helpers/role.helper";

const StressProjectList = () => {
  const navigate = useNavigate();
  const searchParams = { page_size: 10, ...Route.useSearch() };

  const authUser = useAtomValue(authUserAtom);
  const isDoctor = RoleHelper.isDoctor(authUser?.roles || []);

  const { data: pageData, isFetching } =
    useGetStressProjectsQuery(searchParams);

  const columns = useMemo(() => getTableColumns(isDoctor), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  const goToCreate = () => {
    navigate({ to: RouteConfig.ADMIN_STRESS_PROJECT_CREATE.route.to });
  };

  return (
    <div className="flex flex-col gap-6 max-w-[992px] px-4 w-full mx-auto">
      <div className="pt-10 pb-2 flex items-center justify-between">
        <h3 className="font-bold text-[24px]">{isDoctor ? "ストレスチェック一覧" : "ストレスチェック管理"}</h3>
        <div className="flex gap-2">
          {!isDoctor && (
            <>
              <DataTableToolbar table={table} />
              <Button className="min-w-40" onClick={goToCreate}>
                <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
                <span>{"新規作成"}</span>
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
          emptyActions={
            !isDoctor && (
              <Button
                variant="link"
                onClick={goToCreate}
                className="font-normal h-6 text-blue underline"
              >
                <span>{"新規作成"}</span>
              </Button>
            )
          }
        />
      </div>
    </div>
  );
};

export default StressProjectList;
