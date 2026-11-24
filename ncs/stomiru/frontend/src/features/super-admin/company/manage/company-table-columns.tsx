import { Link } from "@tanstack/react-router";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { Company } from "@/types/models/company";
import RouteConfig from "@/configs/route.config";
import CompanyStatusEnum from "@/enums/company-status.enum";
import CommonHelper from "@/helpers/common.helper";
import StringHelper from "@/helpers/string.helper";
import { CompanyStatusBadge } from "@/components/custom/m-badge";
import { RowActionButton } from "@/components/custom/m-row-actions";
import FactCheckIcon from "@/components/icons/fact-check-icon";
import ModeEditIcon from "@/components/icons/mode-edit-icon";

export const getTableColumns = (): ColumnDef<Company>[] => {
  return [
    {
      accessorKey: "id",
      header: "ID",
      size: 60,
      sorter: true,
    },
    {
      id: "search",
      accessorKey: "company_name",
      header: "社名",
      // size: 200,
      cell: ({ row }) => (
        <Link
          to={RouteConfig.SUPER_ADMIN_COMPANY_DETAIL.route.to}
          params={{ companyId: String(row.original.id) }}
          className="underline line-clamp-2 break-words whitespace-normal"
        >
          {row.original.company_name}
        </Link>
      ),
      meta: {
        placeholder: "社名 / 担当者名 / メール / 電話番号",
        variant: "simpleInput",
        displayOrder: 1,
      },
      enableColumnFilter: true,
    },
    {
      accessorKey: "contact_first_name",
      header: "担当者名",
      size: 100,
      cell: ({ row }) => {
        const firstName = row.original.contact_first_name;
        const lastName = row.original.contact_last_name;
        return (
          <div className="line-clamp-2 break-words whitespace-normal">
            {firstName} {lastName}
          </div>
        );
      },
      sorter: true,
    },
    {
      accessorKey: "contact_email",
      header: "メール",
      size: 200,
    },
    {
      accessorKey: "contact_phone",
      header: "電話番号",
      size: 120,
      cell: ({ cell }) => {
        return StringHelper.toPhoneNumber(cell.getValue() as string);
      },
    },
    {
      accessorKey: "max_employee_accounts",
      header: "従業員数",
      size: 140,
      cell: ({ row }) => {
        const company = row.original;
        return (
          <div className="flex gap-[10px] text-sm leading-[21px] font-normal">
            <div className="flex flex-col">
              <span>
                <span>{"マ:"}</span>
                <span>{company.max_manager_accounts}</span>
              </span>
              <span>
                <span className="text-dark-orange">{"休:"}</span>
                <span>{"0"}</span>
              </span>
            </div>
            <div className="flex flex-col">
              <span>
                <span>{"従:"}</span>
                <span>{company.max_employee_accounts}</span>
              </span>
              <span>
                <span className="text-red">{"退:"}</span>
                <span>{"0"}</span>
              </span>
            </div>
          </div>
        );
      },
    },
    {
      accessorKey: "contract_start_date",
      header: "契約開始日",
      size: 90,
      cell: ({ cell }) =>
        (cell.getValue() as Company["contract_start_date"]) ?? "-",
    },
    {
      id: "status",
      accessorKey: "status",
      header: "契約有無",
      size: 100,
      cell: ({ cell }) => (
        <CompanyStatusBadge status={cell.getValue<Company["status"]>()} />
      ),
      meta: {
        label: "契約有無",
        variant: "select",
        controlClassName: "min-w-[140px]",
        options: Object.values(CompanyStatusEnum),
        allValue: "すべて",
        displayOrder: 0,
      },
      enableColumnFilter: true,
      sorter: true,
    },
    {
      id: "actions",
      header: "",
      meta: {
        headerClassName: "w-[220px]",
      },
      cell: ({ row }) => <RowActions row={row} />,
    },
  ];
};

interface RowActionsProps {
  row: Row<Company>;
}

/* eslint-disable react-refresh/only-export-components */
const RowActions = ({ row }: RowActionsProps) => {
  const isPending = row.original.status === CompanyStatusEnum.Pending.value;

  return (
    <div className="flex gap-3">
      <RowActionButton
        label="編集"
        icon={ModeEditIcon}
        to={CommonHelper.replaceUrlParam(
          RouteConfig.SUPER_ADMIN_COMPANY_UPDATE.path,
          {
            companyId: row.original.id,
          }
        )}
      />
      {!isPending && (
        <RowActionButton
          label="従業員"
          icon={FactCheckIcon}
          to={CommonHelper.replaceUrlParam(
            RouteConfig.SUPER_ADMIN_COMPANY_EMPLOYEE.path,
            {
              companyId: row.original.id,
            }
          )}
        />
      )}
    </div>
  );
};
