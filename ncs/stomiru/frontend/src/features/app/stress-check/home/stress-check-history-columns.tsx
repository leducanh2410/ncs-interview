import { Link } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { StressCheck } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import ResponseStatusEnum from "@/enums/response-status.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import {
  ResponseStatusBadge,
  StressCheckStatusBadge,
} from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import FileSearchIcon from "@/components/icons/file-search-icon";

const getResponseStatus = (row: StressCheck): string => {
  const session = row.exam_sessions?.[0];
  if (session?.status === "submitted") {
    return ResponseStatusEnum.Answered.value;
  }
  return ResponseStatusEnum.Unanswered.value;
};

export const getTableColumns = (
  t: (key: string) => string,
  isStaffManager: boolean
): ColumnDef<StressCheck>[] => {
  return [
    {
      accessorKey: "id",
      header: "ID",
      size: 80,
      meta: {
        headerClassName: "w-[80px]",
      },
      sorter: true,
    },
    {
      accessorKey: "title",
      header: t("stressCheckList-staff.title"),
      size: 300,
      meta: {
        cellClassName: "whitespace-normal",
      },
      cell: ({ row }) => {
        const isAnswered =
          getResponseStatus(row.original) === ResponseStatusEnum.Answered.value;
        const to = isAnswered
          ? CommonHelper.replaceUrlParam(
              RouteConfig.APP_STRESS_CHECK_RESULT.path,
              { stressCheckId: row.original.id }
            )
          : CommonHelper.replaceUrlParam(
              RouteConfig.APP_STRESS_CHECK_EXAM.path,
              { stressCheckId: row.original.id }
            );

        return (
          <Link
            to={to}
            className="underline line-clamp-2 break-words whitespace-normal w-[280px]"
          >
            {row.original.title}
          </Link>
        );
      },
      sorter: true,
    },
    {
      accessorKey: "year",
      header: t("stressCheckList-staff.implementationYear"),
      size: 120,
      cell: ({ cell }) =>
        `${cell.getValue<string>()}${t("stressCheckList-staff.year")}`,
      meta: {
        headerClassName: "w-[120px]",
      },
      sorter: true,
    },
    {
      accessorKey: "start_date",
      header: t("stressCheckList-staff.period"),
      size: 200,
      cell: ({ row }) => (
        <span>
          {DateTimeHelper.format(row.original.start_date)}
          {" 〜 "}
          {DateTimeHelper.format(row.original.end_date)}
        </span>
      ),
      meta: {
        headerClassName: "w-[200px]",
      },
      sorter: true,
    },
    {
      id: "status",
      accessorKey: "status",
      header: t("stressCheckList-staff.status"),
      size: 110,
      cell: ({ cell }) => (
        <StressCheckStatusBadge
          status={cell.getValue<StressCheck["status"]>()}
        />
      ),
      meta: {
        headerClassName: "w-[110px] text-center",
        cellClassName: "text-center",
      },
      sorter: true,
    },
    {
      id: "response_status",
      accessorKey: "response_status",
      header: t("stressCheckList-staff.answerStatus"),
      size: 110,
      cell: ({ row }) => (
        <ResponseStatusBadge status={getResponseStatus(row.original)} />
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
      cell: ({ row }) => <RowActions row={row} />,
      meta: {
        hiddenColumn: !isStaffManager,
        headerClassName: "w-[100px]",
      },
    },
  ];
};

interface RowActionsProps {
  row: Row<StressCheck>;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row }: RowActionsProps) => {
  return (
    <div className="flex gap-3">
      <RowActionButton
        label="レポート"
        icon={FileSearchIcon}
        to={CommonHelper.replaceUrlParam(
          RouteConfig.APP_STRESS_CHECK_REPORT.path,
          {
            stressCheckId: row.original.id,
          }
        )}
      />
    </div>
  );
};
