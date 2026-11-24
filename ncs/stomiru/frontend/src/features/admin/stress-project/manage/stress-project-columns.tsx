import { Link } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import StressCheckStatusEnum from "@/enums/stress-check-status.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import { StressCheckStatusBadge } from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import FactCheckIcon from "@/components/icons/fact-check-icon";
import FileSearchIcon from "@/components/icons/file-search-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";

export const getTableColumns = (isDoctor = false): ColumnDef<StressCheck>[] => {
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
          to={RouteConfig.ADMIN_STRESS_PROJECT_DETAIL.route.to}
          params={{ stressProjectId: String(row.original.id) }}
          className="underline line-clamp-2 break-words whitespace-normal"
          title={row.original.title}
        >
          {row.original.title}
        </Link>
      ),
      meta: {
        headerClassName: "w-[300px]",
      },
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
          <span className="flex flex-col">
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
      id: "status",
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
      cell: ({ row }) => <RowActions row={row} isDoctor={isDoctor} />,
      meta: {
        headerClassName: "w-[220px]",
      },
    },
  ];
};

interface RowActionsProps {
  row: Row<StressCheck>;
  isDoctor?: boolean;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row, isDoctor }: RowActionsProps) => {
  const isFinished =
    row.original.status === StressCheckStatusEnum.Finished.value;

  return (
    <div className="flex gap-3">
      {!isDoctor && (
        <RowActionButton
          label="編集"
          icon={ModeEditIcon}
          to={CommonHelper.replaceUrlParam(
            RouteConfig.ADMIN_STRESS_PROJECT_UPDATE.path,
            {
              stressProjectId: row.original.id,
            }
          )}
        />
      )}
      <RowActionButton
        label="受検者"
        icon={FactCheckIcon}
        to={CommonHelper.replaceUrlParam(RouteConfig.ADMIN_EXAMINEE.path, {
          stressProjectId: row.original.id,
        })}
      />
      {isFinished && (
        <RowActionButton
          label="レポート"
          icon={FileSearchIcon}
          to={CommonHelper.replaceUrlParam(
            RouteConfig.ADMIN_STRESS_PROJECT_REPORT.path,
            {
              stressProjectId: row.original.id,
            }
          )}
        />
      )}
    </div>
  );
};
