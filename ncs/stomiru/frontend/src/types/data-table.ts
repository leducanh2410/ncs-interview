import React from "react";
import type { RowData } from "@tanstack/react-table";
import type { DataTableConfig } from "@/configs/data-table.config";

declare module "@tanstack/react-table" {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnMeta<TData extends RowData, TValue> {
    label?: string;
    placeholder?: string;
    variant?: FilterVariant;
    options?: Option[];
    range?: [number, number];
    unit?: string;
    icon?: React.FC<React.SVGProps<SVGSVGElement>>;
    controlClassName?: string;
    hiddenColumn?: boolean;
    resource?: string;
    displayOrder?: number;
    allValue?: string;
    headerClassName?: string;
    cellClassName?: string;
  }
}

export interface Option {
  label: string;
  value: string | boolean;
  count?: number;
  icon?: React.FC<React.SVGProps<SVGSVGElement>>;
}

export type FilterVariant = DataTableConfig["filterVariants"][number];
