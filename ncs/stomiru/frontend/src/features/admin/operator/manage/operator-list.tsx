import { useMemo } from "react";
import { useGetOperatorsQuery } from "../operator.api";
import { useNavigate } from "@tanstack/react-router";
import RouteConfig from "@/configs/route.config";
import { DataTable } from "@/components/data-table/data-table";
import AddIcon from "@/components/icons/add-icon";
import { Button } from "@/components/ui/button";
import { getTableColumns } from "./operator-columns";
import { useDataTable } from "@/hooks/use-data-table";
import { Route } from "@/routes/_authenticated/admin/operator/index";

const OperatorList = () => {
  const navigate = useNavigate();
  const searchParams = { page_size: 10, ...Route.useSearch() };

  const { data: pageData, isFetching } = useGetOperatorsQuery(searchParams);

  const columns = useMemo(() => getTableColumns(), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  const goToCreate = () => {
    navigate({ to: RouteConfig.ADMIN_OPERATOR_CREATE.route.to });
  };

  return (
    <div className="flex flex-col gap-6 max-w-[992px] px-4 w-full mx-auto">
      <div className="pt-10 pb-2 flex items-center justify-between">
        <h3 className="font-bold text-[24px] leading-[29px]">{"担当者一覧"}</h3>
        <div className="flex gap-2">
          <Button className="min-w-40" onClick={goToCreate}>
            <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
            <span>{"担当者登録"}</span>
          </Button>
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

export default OperatorList;
