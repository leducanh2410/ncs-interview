import { useMemo } from "react";
import { Link, useNavigate } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { Company } from "@/types/models/company";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import CompanyStatusEnum from "@/enums/company-status.enum";
import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import { StressCheckStatusBadge } from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import { DataTable } from "@/components/data-table/data-table";
import AddIcon from "@/components/icons/add-icon";
import FactCheckIcon from "@/components/icons/fact-check-icon";
import FileSearchIcon from "@/components/icons/file-search-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { Button } from "@/components/ui/button";
import { useGetStressProjectsQuery } from "@/features/admin/stress-project/stress-project.api";
import { useDataTable } from "@/hooks/use-data-table";
import { Route } from "@/routes/_authenticated/super-admin/companies/$companyId";

const getTableColumns = (): ColumnDef<StressCheck>[] => {
  return [
    {
      accessorKey: "id",
      header: "ID",
      size: 100,
      meta: {
        headerClassName: "w-[100px]",
      },
      sorter: true,
    },
    {
      accessorKey: "title",
      header: "タイトル",
      size: 300,
      cell: ({ row }) => (
        <Link
          to={RouteConfig.SUPER_ADMIN_COMPANY_STRESS_PROJECT_DETAIL.route.to}
          params={{
            companyId: String(row.original.company_id),
            stressProjectId: String(row.original.id),
          }}
          className="underline line-clamp-2 break-words whitespace-normal"
        >
          {row.original.title}
        </Link>
      ),
    },
    {
      accessorKey: "year",
      header: "実施年度",
      size: 100,
      cell: ({ cell }) => `${cell.getValue<number>()}年度`,
      meta: {
        headerClassName: "w-[120px]",
      },
      sorter: true,
    },
    {
      accessorKey: "start_date",
      header: "期間",
      size: 90,
      cell: ({ row }) => {
        return (
          <span className="flex">
            <span>{DateTimeHelper.format(row.original.start_date)}</span>
            <span>{` 〜 ${DateTimeHelper.format(row.original.end_date)}`}</span>
          </span>
        );
      },
      meta: {
        headerClassName: "w-[140px]",
      },
    },
    {
      accessorKey: "status",
      header: "ステータス",
      size: 100,
      cell: ({ cell }) => (
        <StressCheckStatusBadge
          status={cell.getValue<StressCheck["status"]>()}
          useJapaneseLabel
        />
      ),
      meta: {
        headerClassName: "w-[110px] text-center",
        cellClassName: "text-center",
      },
      sorter: true,
    },
    {
      id: "actions",
      header: "",
      size: 220,
      cell: ({ row }) => <RowActions row={row} />,
      meta: {
        headerClassName: "w-[220px]",
      },
    },
  ];
};

interface RowActionsProps {
  row: Row<StressCheck>;
}

const RowActions = ({ row }: RowActionsProps) => {
  const isFinished =
    row.original.status === StressCheckStatusEnum.Finished.value;

  return (
    <div className="flex gap-3">
      <RowActionButton
        label="編集"
        icon={ModeEditIcon}
        to={CommonHelper.replaceUrlParam(
          RouteConfig.SUPER_ADMIN_COMPANY_STRESS_PROJECT_UPDATE.path,
          {
            companyId: row.original.company_id,
            stressProjectId: row.original.id,
          }
        )}
        search={{
          continue: CommonHelper.replaceUrlParam(
            RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.path,
            {
              companyId: row.original.company_id,
            }
          ),
        }}
      />
      <RowActionButton
        label="受検者"
        icon={FactCheckIcon}
        to={CommonHelper.replaceUrlParam(
          RouteConfig.SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE.path,
          {
            stressProjectId: row.original.id,
            companyId: row.original.company_id,
          }
        )}
      />
      {isFinished && (
        <RowActionButton
          label="レポート"
          icon={FileSearchIcon}
          to={CommonHelper.replaceUrlParam(
            RouteConfig.SUPER_ADMIN_COMPANY_STRESS_PROJECT_REPORT.path,
            {
              stressProjectId: row.original.id,
              companyId: row.original.company_id,
            }
          )}
        />
      )}
    </div>
  );
};

interface StressCheckTableProps {
  company: Company;
}

export const StressCheckTable = ({ company }: StressCheckTableProps) => {
  const navigate = useNavigate();
  const searchParams = {
    ...Route.useSearch(),
    company_id: company.id,
    page_size: 3,
  };
  const isPending = company.status === CompanyStatusEnum.Pending.value;

  const { data: pageData, isFetching } = useGetStressProjectsQuery({
    ...searchParams,
    company_id: company.id,
  });

  const columns = useMemo(() => getTableColumns(), []);
  const { table } = useDataTable({
    searchParams,
    data: pageData?.data?.data ?? [],
    columns,
    pageCount: pageData?.data?.last_page ?? 0,
    rowCount: pageData?.data?.total ?? 0,
    getRowId: (originalRow) => `stc_${originalRow.id}`,
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="pt-4 flex gap-2.5 items-end justify-between">
        <div>
          <span className="text-base leading-[20px] font-bold">
            {"ストレスチェック一覧"}
          </span>
        </div>
        <div>
          {!isPending && (
            <Button
              variant="outline"
              className="font-bold min-w-30 h-[28px] border-primary border-2"
              onClick={() => {
                void navigate({
                  to: CommonHelper.replaceUrlParam(
                    RouteConfig.SUPER_ADMIN_COMPANY_STRESS_PROJECT_CREATE.path,
                    {
                      companyId: company.id,
                    }
                  ),
                  search: {
                    continue: CommonHelper.replaceUrlParam(
                      RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.path,
                      {
                        companyId: company.id,
                      }
                    ),
                  },
                });
              }}
            >
              <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
              <span>{"新規作成"}</span>
            </Button>
          )}
        </div>
      </div>
      <DataTable isLoading={isFetching} table={table} />
    </div>
  );
};
