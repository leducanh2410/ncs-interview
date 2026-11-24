import type { ColumnDef } from "@tanstack/react-table";
import type { Log } from "@/types/models/log";
import LogCategoryEnum, { LogActionEnum } from "@/enums/log.enum";
import RoleEnum from "@/enums/role.enum";
import DateTimeHelper from "@/helpers/date-time.helper";
import EnumHelper from "@/helpers/enum.helper";

export const getTableColumns = (): ColumnDef<Log>[] => {
  return [
    {
      accessorKey: "created_at",
      header: "日時",
      cell: ({ getValue }) =>
        DateTimeHelper.format(getValue() as string, "yyyy-MM-dd HH:mm:ss"),
      id: "created_at",
      meta: {
        headerClassName: "w-[170px]",
        label: "期間",
        variant: "dateRange",
      },
      enableColumnFilter: true,
      sorter: true,
    },
    {
      accessorKey: "actor_id",
      header: "作業者ID",
      cell: ({ getValue }) => getValue() ?? "ー",
    },
    {
      accessorKey: "actor_name",
      header: "作業者名",
      cell: ({ row }) => (
        <div className="line-clamp-2 break-words whitespace-normal">
          {row.original.actor_name}
        </div>
      ),
    },
    {
      accessorKey: "actor_role",
      header: "権限",
      cell: ({ getValue }) => {
        const roles = getValue() as string[];
        return (
          roles.map((role) => EnumHelper.getText(RoleEnum, role)).join(", ") ||
          "ー"
        );
      },
      id: "role",
      meta: {
        label: "権限",
        variant: "select",
        controlClassName: "min-w-[140px]",
        options: Object.values(RoleEnum),
        allValue: "すべて",
        displayOrder: 0,
      },
      enableColumnFilter: true,
    },
    {
      accessorKey: "category",
      header: "カテゴリー",
      cell: ({ getValue }) =>
        EnumHelper.getText(LogCategoryEnum, getValue() as string) ?? "ー",
    },
    {
      accessorKey: "target_id",
      header: "対象ID",
      cell: ({ getValue }) => getValue() ?? "ー",
    },
    {
      accessorKey: "action",
      header: "Action",
      cell: ({ getValue }) =>
        EnumHelper.getText(LogActionEnum, getValue() as string) ?? "ー",
    },
  ];
};
