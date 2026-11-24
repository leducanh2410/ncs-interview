import type { ColumnDef, Row } from "@tanstack/react-table";
import { EyeOff } from "lucide-react";
import type { Examinee } from "@/types/models/stress-check";
import RouteConfig from "@/configs/route.config";
import EmployeeStatusEnum from "@/enums/employee-status.enum";
import GenderEnum from "@/enums/gender.enum";
import ResponseStatusEnum from "@/enums/response-status.enum";
import CommonHelper from "@/helpers/common.helper";
import EnumHelper from "@/helpers/enum.helper";
import RoleHelper from "@/helpers/role.helper";
import {
  JudgementBadge,
  ResponseStatusBadge,
} from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import FileSearchIcon from "@/components/icons/file-search-icon";

export const getTableColumns = (
  stressProjectId: number,
  shortCut?: boolean
): ColumnDef<Examinee>[] => {
  return [
    {
      accessorKey: "employee_code",
      header: "従業員番号",
      meta: {
        headerClassName: "w-[130px]",
      },
      sorter: true,
    },
    {
      accessorKey: "name",
      header: "氏名",
      size: 152,
      id: "search",
      cell: ({ row }) => (
        <div
          className="line-clamp-2 break-words whitespace-normal"
          title={row.original.name}
        >
          {row.original.name}
        </div>
      ),
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
      meta: {
        headerClassName: "w-20",
      },
      cell: ({ row }) =>
        EnumHelper.getText(GenderEnum, row.original.user_profile.gender),
      sorter: true,
    },
    {
      accessorKey: "role",
      header: "権限",
      meta: {
        headerClassName: "w-25",
      },
      cell: ({ row }) => RoleHelper.getAuthorityLabel(row.original.roles),
      sorter: true,
    },
    {
      accessorKey: "department",
      header: "部署",
      size: 152,
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
      accessorKey: "response_status",
      header: "回答状況",
      size: 100,
      cell: ({ cell }) => (
        <ResponseStatusBadge
          status={cell.getValue<string>()}
          useJapaneseLabel
        />
      ),
      sorter: true,
    },
    {
      accessorKey: "judgement",
      header: "判定",
      size: 70,
      meta: {
        headerClassName: "w-[70px]",
      },
      cell: ({ row }) => <JudgementBadge status={row.original.judgement} />,
      sorter: true,
    },
    {
      id: "actions",
      header: "",
      cell: ({ row }) => (
        <RowActions
          row={row}
          stressProjectId={stressProjectId}
          shortCut={shortCut}
        />
      ),
      meta: {
        headerClassName: "w-[160px]",
      },
    },
  ];
};

interface RowActionsProps {
  row: Row<Examinee>;
  stressProjectId: number;
  shortCut?: boolean;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row, stressProjectId, shortCut }: RowActionsProps) => {
  const isAnswered =
    ResponseStatusEnum.Answered.value === row.original.response_status;
  const isIneligible =
    row.original.employee_status === EmployeeStatusEnum.Retired.value ||
    row.original.employee_status === EmployeeStatusEnum.OnLeave.value;

  return (
    <div className="flex gap-3">
      {isIneligible ? (
        <span className="flex items-center gap-0.5 text-[14px] leading-[17px] text-black font-normal">
          <EyeOff className="size-[18px] text-[#ACADB0]" />
          <span>{"対象外"}</span>
        </span>
      ) : (
        isAnswered && (
          <RowActionButton
            label="検査結果"
            icon={FileSearchIcon}
            to={
              shortCut
                ? CommonHelper.replaceUrlParam(
                    RouteConfig
                      .SUPER_ADMIN_COMPANY_STRESS_PROJECT_EXAMINEE_RESULT.path,
                    {
                      companyId: row.original.company_id || 0,
                      stressProjectId: stressProjectId,
                      examineeId: row.original.id,
                    }
                  )
                : CommonHelper.replaceUrlParam(
                    RouteConfig.ADMIN_EXAMINEE_RESULT.path,
                    {
                      stressProjectId: stressProjectId,
                      examineeId: row.original.id,
                    }
                  )
            }
          />
        )
      )}
    </div>
  );
};
