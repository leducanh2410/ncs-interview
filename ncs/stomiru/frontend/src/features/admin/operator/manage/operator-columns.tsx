import { useState } from "react";
import {
  type Operator,
  useResendAdminInvitationMutation,
} from "../operator.api";
import { useNavigate } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { User } from "@/types/models/user";
import RouteConfig from "@/configs/route.config";
import UserStatusEnum from "@/enums/user-status.enum";
import CommonHelper from "@/helpers/common.helper";
import RoleHelper from "@/helpers/role.helper";
import { LoaderCircle } from "@/components/custom/loader";
import { UserStatusBadge } from "@/components/custom/m-badge";
import { toastSuccess } from "@/components/custom/m-toast";
import DeleteIcon from "@/components/icons/delete-icon";
import MailOutlineIcon from "@/components/icons/mail-outline-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { Button } from "@/components/ui/button";
import { OperatorDeleteModal } from "./operator-delete-modal";

export const getTableColumns = (): ColumnDef<Operator>[] => {
  return [
    {
      accessorKey: "name",
      header: "管理者名",
      size: 277,
      cell: ({ row }) => (
        <div
          className="line-clamp-2 break-words whitespace-normal"
          title={row.original.name}
        >
          {row.original.name}
        </div>
      ),
      sorter: true,
    },
    {
      accessorKey: "email",
      header: "メール",
      size: 277,
      sorter: true,
    },
    {
      accessorKey: "role",
      header: "権限",
      size: 130,
      cell: ({ row }) => RoleHelper.getAdminAuthorityLabel(row.original.roles),
      sorter: true,
    },
    {
      accessorKey: "status",
      header: "ステータス",
      size: 110,
      meta: {
        headerClassName: "w-[110px] text-center",
        cellClassName: "text-center",
      },
      cell: ({ cell }) => (
        <UserStatusBadge status={cell.getValue<User["status"]>()} />
      ),
      sorter: true,
    },
    {
      id: "actions",
      header: "",
      size: 80,
      meta: {
        headerClassName: "w-[180px]",
      },
      cell: ({ row }) => <RowActions row={row} />,
    },
  ];
};

interface RowActionsProps {
  row: Row<Operator>;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row }: RowActionsProps) => {
  const navigate = useNavigate();
  const [deleteModalOpen, setDeleteModalOpen] = useState(false);

  const isActive = row.original.status === UserStatusEnum.Active.value;
  const isPending = row.original.status === UserStatusEnum.Pending.value;
  const resendAdminInvitationMutation = useResendAdminInvitationMutation(
    row.original.id
  );

  return (
    <>
      <div className="flex gap-3">
        <Button
          variant="link"
          className="text-sm text-black font-normal underline gap-0.5 p-0"
          onClick={(e) => {
            e.stopPropagation();
            void navigate({
              to: CommonHelper.replaceUrlParam(
                RouteConfig.ADMIN_OPERATOR_UPDATE.path,
                {
                  operatorId: row.original.id,
                }
              ),
            });
          }}
        >
          <ModeEditIcon svgClassName="size-[18px]" />
          <span>{"編集"}</span>
        </Button>

        {isPending && (
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

        {isActive && (
          <Button
            variant="link"
            className="text-sm text-red font-normal underline gap-0.5 p-0"
            onClick={(e) => {
              e.stopPropagation();
              setDeleteModalOpen(true);
            }}
          >
            <DeleteIcon svgClassName="size-[18px]" />
            <span>{"削除申請"}</span>
          </Button>
        )}
      </div>
      {isActive && (
        <OperatorDeleteModal
          open={deleteModalOpen}
          setOpen={setDeleteModalOpen}
          operator={row.original}
        />
      )}
    </>
  );
};
