import { Link } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { Employee } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import GenderEnum from "@/enums/gender.enum";
import UserStatusEnum from "@/enums/user-status.enum";
import CommonHelper from "@/helpers/common.helper";
import EnumHelper from "@/helpers/enum.helper";
import RoleHelper from "@/helpers/role.helper";
import { EmployeeStatusBadge } from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { ConfirmRequestDeletionModal } from "./confirm-request-deletion-modal";

export const getTableColumns = (shortCut = false): ColumnDef<Employee>[] => {
  return [
    {
      header: "従業員番号",
      accessorKey: "employee_code",
      meta: {
        headerClassName: "w-[130px]",
      },
      sorter: true,
    },
    {
      id: "search",
      accessorKey: "name",
      header: "氏名",
      cell: ({ row }) => (
        <Link
          to={
            shortCut
              ? RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE_DETAIL.route.to
              : RouteConfig.ADMIN_EMPLOYEE_DETAIL.route.to
          }
          params={
            shortCut
              ? {
                  companyId: String(row.original.company_id || 0),
                  employeeId: String(row.original.id),
                }
              : { employeeId: String(row.original.id) }
          }
          className="underline line-clamp-2 break-words whitespace-normal"
          title={row.original.name}
        >
          {row.original.name}
        </Link>
      ),
      // size: 222,
      meta: {
        placeholder: "氏名または従業員番号",
        variant: "simpleInput",
        controlClassName: "w-60",
        displayOrder: 0,
      },
      enableColumnFilter: true,
      sorter: true,
    },
    {
      accessorKey: "gender",
      header: "性別",
      size: 80,
      cell: ({ row }) =>
        EnumHelper.getText(GenderEnum, row.original.user_profile.gender),
      sorter: true,
    },
    {
      accessorKey: "role",
      header: "権限",
      size: 100,
      cell: ({ row }) => RoleHelper.getAuthorityLabel(row.original.roles),
      sorter: true,
    },
    {
      accessorKey: "department",
      header: "部署",
      size: 222,
      cell: ({ row }) => (
        <div
          className="line-clamp-2 break-words whitespace-normal"
          title={row.original.user_profile?.department ?? undefined}
        >
          {row.original.user_profile?.department ?? ""}
        </div>
      ),
      sorter: true,
    },
    {
      accessorKey: "employee_status",
      header: "ステータス",
      size: 110,
      meta: {
        headerClassName: "w-[110px] text-center",
        cellClassName: "text-center",
      },
      cell: ({ cell }) => (
        <EmployeeStatusBadge
          status={cell.getValue<Employee["employee_status"]>()}
          blankOnActive
        />
      ),
      sorter: true,
    },
    {
      id: "actions",
      header: "",
      size: 80,
      meta: {
        headerClassName: "w-20",
      },
      cell: ({ row }) => <RowActions row={row} shortCut={shortCut} />,
    },
  ];
};

interface RowActionsProps {
  row: Row<Employee>;
  shortCut?: boolean;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row, shortCut }: RowActionsProps) => {
  const isPendingDelete =
    row.original.status === UserStatusEnum.PendingDeletion.value;

  return (
    <div className="flex gap-3">
      <RowActionButton
        label="編集"
        icon={ModeEditIcon}
        to={CommonHelper.replaceUrlParam(
          shortCut
            ? RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE_UPDATE.path
            : RouteConfig.ADMIN_EMPLOYEE_UPDATE.path,
          {
            employeeId: row.original.id,
            companyId: row.original.company_id || 0,
          }
        )}
      />
      {shortCut && isPendingDelete && (
        <ConfirmRequestDeletionModal employee={row.original} />
      )}
    </div>
  );
};
