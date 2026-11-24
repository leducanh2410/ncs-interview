import { useMemo } from "react";
import { useResendAdminInvitationMutation } from "../company.api";
import { useNavigate } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { Company } from "@/types/models/company";
import type { User } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import CompanyStatusEnum from "@/enums/company-status.enum";
import UserStatusEnum from "@/enums/user-status.enum";
import CommonHelper from "@/helpers/common.helper";
import DateTimeHelper from "@/helpers/date-time.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { UserStatusBadge } from "@/components/custom/m-badge";
import { toastSuccess } from "@/components/custom/m-toast";
import { DataTable } from "@/components/data-table/data-table";
import AddIcon from "@/components/icons/add-icon";
import MailOutlineIcon from "@/components/icons/mail-outline-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { Button } from "@/components/ui/button";
import { useDataTable } from "@/hooks/use-data-table";

const getTableColumns = (): ColumnDef<User>[] => {
  return [
    {
      accessorKey: "name",
      header: "管理者名",
      size: 70,
      cell: ({ row }) => (
        <div className="line-clamp-2 break-words whitespace-normal">
          {row.original.name}
        </div>
      ),
      sorter: (a, b, id) =>
        String(a.getValue(id) ?? "").localeCompare(
          String(b.getValue(id) ?? ""),
          "ja"
        ),
    },
    {
      accessorKey: "email",
      header: "メール",
    },
    {
      accessorKey: "authority",
      header: "権限",
      size: 130,
      cell: ({ row }) => RoleHelper.getAdminAuthorityLabel(row.original.roles),
    },
    {
      accessorKey: "created_at",
      header: "作成日",
      size: 140,
      cell: ({ getValue }) =>
        DateTimeHelper.format(getValue() as string, "yyyy-MM-dd"),
    },
    {
      accessorKey: "status",
      header: "ステータス",
      size: 120,
      cell: ({ cell }) => (
        <UserStatusBadge status={cell.getValue<User["status"]>()} />
      ),
      sorter: (a, b, id) =>
        String(a.getValue(id) ?? "").localeCompare(
          String(b.getValue(id) ?? ""),
          "ja",
          { numeric: true }
        ),
    },
    {
      id: "actions",
      header: "",
      size: 120,
      cell: ({ row }) => <RowActions row={row} />,
    },
  ];
};

interface RowActionsProps {
  row: Row<User>;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row }: RowActionsProps) => {
  const navigate = useNavigate();

  const isVerified = row.original.status !== UserStatusEnum.Pending.value;
  const resendAdminInvitationMutation = useResendAdminInvitationMutation(
    row.original.id
  );

  return (
    <div className="flex gap-3">
      <Button
        variant="link"
        className="text-sm text-black font-normal underline gap-0.5 p-0"
        onClick={(e) => {
          e.stopPropagation();
          void navigate({
            to: CommonHelper.replaceUrlParam(
              RouteConfig.SUPER_ADMIN_COMPANY_ADMIN_UPDATE.path,
              {
                companyId: row.original.company_id,
                adminId: row.original.id,
              }
            ),
          });
        }}
      >
        <ModeEditIcon svgClassName="size-[18px]" />
        <span>{"編集"}</span>
      </Button>
      {!isVerified && (
        <Button
          variant="link"
          className="text-sm text-black font-normal underline gap-0.5 p-0"
          onClick={async (e) => {
            e.stopPropagation();
            await resendAdminInvitationMutation.mutateAsync();
            toastSuccess("パスワード再設定メールを送信しました。");
          }}
          disabled={resendAdminInvitationMutation.isPending}
        >
          {resendAdminInvitationMutation.isPending ? (
            <LoaderCircle className="size-[18px]" />
          ) : (
            <MailOutlineIcon svgClassName="size-[18px]" />
          )}
          <span>{"メール再送"}</span>
        </Button>
      )}
    </div>
  );
};

interface AdminTableProps {
  company: Company;
  data?: User[];
}

export const AdminTable = ({ company, data }: AdminTableProps) => {
  const navigate = useNavigate();
  const isPending = company.status === CompanyStatusEnum.Pending.value;

  const columns = useMemo(() => getTableColumns(), []);
  const { table } = useDataTable({
    searchParams: {},
    data: data ?? [],
    columns,
    pageCount: 1,
    rowCount: data?.length ?? 0,
    getRowId: (originalRow) => String(originalRow.id),
  });

  return (
    <div className="flex flex-col gap-4">
      <div className="pt-4 flex gap-2.5 items-end justify-between">
        <div>
          <span className="text-base leading-[20px] font-bold">
            {"管理者・産業医"}
          </span>
        </div>
        <div>
          {!isPending && (
            <Button
              variant="outline"
              className="font-bold min-w-30 h-[28px] border-primary border-2"
              onClick={() =>
                navigate({
                  to: RouteConfig.SUPER_ADMIN_COMPANY_ADMIN_CREATE.route.to,
                })
              }
            >
              <AddIcon data-icon="inline-start" svgClassName="size-[18px]" />
              <span>{"管理者登録"}</span>
            </Button>
          )}
        </div>
      </div>
      <DataTable table={table} />
    </div>
  );
};
