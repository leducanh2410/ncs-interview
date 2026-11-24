import { useMemo } from "react";
import type { ColumnDef, Row } from "@tanstack/react-table";
import type { Company } from "@/types/models/company";
import RouteConfig from "@/configs/route.config";
import CommonHelper from "@/helpers/common.helper";
import StringHelper from "@/helpers/string.helper";
import { CompanyStatusBadge } from "@/components/custom/m-badge";
import { DataTable } from "@/components/data-table/data-table";
import ModeEditIcon from "@/components/icons/mode-edit-icon";
import { useDataTable } from "@/hooks/use-data-table";
import { RowActionButton } from "@/components/custom/m-row-actions";
import FactCheckIcon from "@/components/icons/fact-check-icon";
import CompanyStatusEnum from "@/enums/company-status.enum";

const getTableColumns = (): ColumnDef<Company>[] => {
  return [
    {
      accessorKey: "id",
      header: "ID",
      size: 60,
      sorter: (a, b, id) =>
        String(a.getValue(id) ?? "").localeCompare(String(b.getValue(id) ?? ""), "ja"),
    },
    {
      accessorKey: "contact_first_name",
      header: "担当者名",
      size: 100,
      cell: ({ row }) => {
        const firstName = row.original.contact_first_name;
        const lastName = row.original.contact_last_name;
        return `${lastName} ${firstName}`;
      },
      sorter: (a, b, id) =>
        String(a.getValue(id) ?? "").localeCompare(String(b.getValue(id) ?? ""), "ja"),
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
      accessorKey: "contract_end_date",
      header: "契約終了日",
      size: 90,
      cell: ({ cell }) =>
        (cell.getValue() as Company["contract_end_date"]) ?? "なし",
    },
    {
      id: "status",
      accessorKey: "status",
      header: "契約有無",
      size: 100,
      cell: ({ cell }) => (
        <CompanyStatusBadge status={cell.getValue<Company["status"]>()} />
      ),
      sorter: (a, b, id) =>
        String(a.getValue(id) ?? "").localeCompare(String(b.getValue(id) ?? ""), "ja", { numeric: true }),
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
  row: Row<Company>;
}

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
        <RowActionButton label="従業員" icon={FactCheckIcon} to={CommonHelper.replaceUrlParam(
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

interface ContactTableProps {
  data?: Company[];
}

export const ContactTable = ({ data }: ContactTableProps) => {
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
    <div className="">
      <DataTable table={table} />
    </div>
  );
};
