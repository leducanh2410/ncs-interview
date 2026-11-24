import { type ComponentProps, Fragment, type ReactNode } from "react";
import DatabaseFailIcon from "../icons/database-fail-icon";
import ArrowUpIcon from "../icons/arrow-up-icon";
import ArrowDownIcon from "../icons/arrow-down-icon";
import SortDownIcon from "../icons/sort-down-icon";
import { type Table as TanstackTable, flexRender } from "@tanstack/react-table";
import { DataTablePagination } from "@/components/data-table/data-table-pagination";
import { DataTableRowLoading } from "@/components/data-table/data-table-row-loading";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";
import SortTriangleUnsetIcon from "../icons/sort-triangle-unset-icon";
import SortTriangleDownIcon from "../icons/sort-triangle-down-icon";

type DataTableProps<TData> = ComponentProps<"div"> & {
  table: TanstackTable<TData>;
  actionBar?: ReactNode;
  isLoading?: boolean;
  emptyClassName?: string;
  emptyActions?: ReactNode;
  emptyMessage?: string;
};

export const DataTable = <TData,>({
  table,
  children,
  isLoading,
  emptyClassName,
  emptyActions,
  emptyMessage,
}: DataTableProps<TData>) => {
  return (
    <>
      {children}
      <Table>
        <TableHeader className="sticky top-0 z-10">
          {table.getHeaderGroups().map((headerGroup) => (
            <TableRow
              key={headerGroup.id}
              className="bg-[#F8F8FC] px-2 rounded-tl-[8px] rounded-tr-[8px] border-b border-[#EBEBF5]"
            >
              {headerGroup.headers.map((header) => (
                <TableHead
                  key={header.id}
                  colSpan={header.colSpan}
                  className={cn(
                    "font-bold",
                    header.column.columnDef.meta?.headerClassName
                  )}
                >
                  {header.isPlaceholder ? null : (
                    <div
                      className={cn(
                        "flex items-center gap-0",
                        header.column.getCanSort()
                          ? "cursor-pointer select-none"
                          : ""
                      )}
                      onClick={
                        header.column.getCanSort()
                          ? header.column.getToggleSortingHandler()
                          : undefined
                      }
                    >
                      {flexRender(
                        header.column.columnDef.header,
                        header.getContext()
                      )}
                      {header.column.getCanSort() && (
                        <div className="flex items-center">
                          {header.column.getIsSorted() === "asc" ? (
                            <SortTriangleDownIcon size={24} className="text-primary rotate-180" />
                          ) : header.column.getIsSorted() === "desc" ? (
                            <SortTriangleDownIcon size={24} className="text-primary" />
                          ) : (
                            <SortTriangleUnsetIcon size={24} className="text-[#ACADB0]" />
                          )}
                        </div>
                      )}
                    </div>
                  )}
                </TableHead>
              ))}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          <DataTableRowLoading
            isLoading={isLoading}
            colSpan={table.getAllColumns().length}
          />
          <>
            {table.getRowModel().rows?.length ? (
              table.getRowModel().rows.map((row) => (
                <Fragment key={row.id}>
                  <TableRow
                    className="hover:bg-muted/50"
                    data-state={row.getIsSelected() && "selected"}
                  >
                    {row.getVisibleCells().map((cell) => (
                      <TableCell
                        key={cell.id}
                        className={cn(
                          cell.column.columnDef.meta?.cellClassName
                        )}
                      >
                        {flexRender(
                          cell.column.columnDef.cell,
                          cell.getContext()
                        )}
                      </TableCell>
                    ))}
                  </TableRow>
                </Fragment>
              ))
            ) : (
              <TableRow className="!border-none">
                <TableCell
                  colSpan={table.getAllColumns().length}
                  className="min-h-[100px] text-center border-t-0"
                >
                  <div
                    className={cn(
                      "flex flex-col py-10 items-center gap-2",
                      emptyClassName
                    )}
                  >
                    <DatabaseFailIcon size={32} />
                    <span className="text-sm font-bold leading-[17px]">
                      {emptyMessage ?? "データがありません"}
                    </span>
                    {emptyActions && (
                      <div className="flex gap-2 pt-2">{emptyActions}</div>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            )}
          </>
        </TableBody>
      </Table>
      <DataTablePagination table={table} />
    </>
  );
};
