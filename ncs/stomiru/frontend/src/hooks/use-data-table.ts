import { useCallback, useMemo, useState } from "react";
import { useNavigate } from "@tanstack/react-router";
import {
  type ColumnFiltersState,
  type PaginationState,
  type SortingFn,
  type SortingState,
  type TableOptions,
  type TableState,
  type Updater,
  type VisibilityState,
  getCoreRowModel,
  getExpandedRowModel,
  getFilteredRowModel,
  getPaginationRowModel,
  getSortedRowModel,
  useReactTable,
  type RowData,
} from "@tanstack/react-table";

declare module "@tanstack/react-table" {
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  interface ColumnDefBase<TData extends RowData, TValue = unknown> {
    sorter?:
    | boolean
    | ((
      rowA: import("@tanstack/react-table").Row<TData>,
      rowB: import("@tanstack/react-table").Row<TData>,
      columnId: string
    ) => number);
  }
}
import { useDebouncedCallback } from "@/hooks/use-debounced-callback";

const PAGE_KEY = "page";
const PER_PAGE_KEY = "page_size";
const SORT_KEY = "sort";
const DEBOUNCE_MS = 250;

interface UseDataTableProps<TData>
  extends
  Omit<
    TableOptions<TData>,
    | "state"
    | "pageCount"
    | "getCoreRowModel"
    | "manualFiltering"
    | "manualPagination"
    | "manualSorting"
  >,
  Required<Pick<TableOptions<TData>, "pageCount">> {
  initialState?: Omit<Partial<TableState>, "sorting"> & {
    // sorting?: ExtendedColumnSort<TData>[];
  };
  history?: "push" | "replace";
  debounceMs?: number;
  searchParams: Record<string, unknown>;
}

export const useDataTable = <TData>(props: UseDataTableProps<TData>) => {
  const {
    columns,
    pageCount = -1,
    initialState,
    history = "replace",
    debounceMs = DEBOUNCE_MS,
    searchParams,
    ...tableProps
  } = props;

  const navigate = useNavigate();

  const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
    Object.assign(
      columns.reduce<Record<string, boolean>>((acc, column) => {
        if (column.meta?.hiddenColumn) {
          acc[column.id ?? ""] = false;
        }
        return acc;
      }, {}),
      initialState?.columnVisibility ?? {}
    )
  );

  const page = Number(searchParams[PAGE_KEY]) || 1;
  const perPage = Number(searchParams[PER_PAGE_KEY]) || 10;

  const pagination: PaginationState = useMemo(() => {
    return {
      pageIndex: page - 1, // zero-based index -> one-based index
      pageSize: perPage,
    };
  }, [page, perPage]);

  const normalizedSearchParams = searchParams as Record<
    string,
    number | string | string[] | undefined
  >;

  const onSearchParamsChange = useCallback(
    (values: Record<string, number | string | string[] | undefined>) => {
      void navigate({
        to: ".",
        search: values,
        replace: history === "replace",
        resetScroll: false
      });
    },
    [history, navigate]
  );

  const onPaginationChange = (updaterOrValue: Updater<PaginationState>) => {
    if (typeof updaterOrValue === "function") {
      const newPagination = updaterOrValue(pagination);
      onSearchParamsChange({
        ...normalizedSearchParams,
        [PAGE_KEY]: newPagination.pageIndex + 1,
        [PER_PAGE_KEY]: newPagination.pageSize,
      });
    } else {
      onSearchParamsChange({
        ...normalizedSearchParams,
        [PAGE_KEY]: updaterOrValue.pageIndex + 1,
        [PER_PAGE_KEY]: updaterOrValue.pageSize,
      });
    }
  };

  const initialSorting: SortingState = useMemo(() => {
    const sort = searchParams[SORT_KEY] as string;
    if (!sort) return [];
    return sort.split(",").map((s) => {
      const isDesc = s.startsWith("-");
      const sortKey = isDesc ? s.substring(1) : s;
      const column = columns.find(
        (c) =>
          c.id === sortKey ||
          (c as { accessorKey?: string }).accessorKey === sortKey
      );
      const id = column?.id ?? (column as { accessorKey?: string })?.accessorKey ?? sortKey;
      return {
        id,
        desc: isDesc,
      };
    });
  }, [searchParams, columns]);

  const [sorting, setSorting] = useState<SortingState>(initialSorting);

  const onSortingChange = useCallback(
    (updaterOrValue: Updater<SortingState>) => {
      setSorting((prev) => {
        const next =
          typeof updaterOrValue === "function"
            ? updaterOrValue(prev)
            : updaterOrValue;

        // Determine if we need to call API (push to URL)
        const hasApiSort = (sortState: SortingState) =>
          sortState.some((s) => {
            const col = columns.find(
              (c) =>
                c.id === s.id ||
                (c as { accessorKey?: string }).accessorKey === s.id
            );
            return col && col.sorter === true;
          });

        if (hasApiSort(next) || hasApiSort(prev)) {
          const sortParams = next
            .filter((s) => {
              const col = columns.find(
                (c) =>
                  c.id === s.id ||
                  (c as { accessorKey?: string }).accessorKey === s.id
              );
              return col && col.sorter === true;
            })
            .map((s) => `${s.desc ? "-" : ""}${s.id}`)
            .join(",");

          onSearchParamsChange({
            ...normalizedSearchParams,
            [SORT_KEY]: sortParams || undefined,
            [PAGE_KEY]: 1,
          });
        }

        return next;
      });
    },
    [onSearchParamsChange, columns, normalizedSearchParams]
  );

  const filterableColumns = useMemo(() => {
    return columns.filter((column) => column.enableColumnFilter);
  }, [columns]);

  const debouncedSetFilterValues = useDebouncedCallback(
    (values: typeof searchParams) => {
      onSearchParamsChange({ ...values, [PAGE_KEY]: 1 });
    },
    debounceMs
  );

  const columnFilters: ColumnFiltersState = useMemo(() => {
    return Object.entries(searchParams).reduce<ColumnFiltersState>(
      (filters, [key, value]) => {
        if (value !== null) {
          const filterableColumn = filterableColumns.find(
            (column) => column.id === key
          );
          if (filterableColumn) {
            filters.push({
              id: key,
              value,
            });
          }
        }
        return filters;
      },
      []
    );
  }, [filterableColumns, searchParams]);

  const onColumnFiltersChange = useCallback(
    (updaterOrValue: Updater<ColumnFiltersState>) => {
      const next =
        typeof updaterOrValue === "function"
          ? updaterOrValue(columnFilters)
          : updaterOrValue;

      const filterUpdates = next.reduce<
        Record<string, string | string[] | undefined>
      >((acc, filter) => {
        const filterableColumn = filterableColumns.find(
          (column) => column.id === filter.id
        );
        if (filterableColumn) {
          const value = filter.value as string | string[];
          acc[filter.id] = value;
        }
        return acc;
      }, {});

      for (const prevFilter of columnFilters) {
        if (!next.some((filter) => filter.id === prevFilter.id)) {
          filterUpdates[prevFilter.id] = undefined;
        }
      }

      debouncedSetFilterValues(filterUpdates);
    },
    [columnFilters, debouncedSetFilterValues, filterableColumns]
  );

  const processedColumns = useMemo(() => {
    return columns.map((col) => {
      const isFunction = typeof col.sorter === "function";
      const isTrue = col.sorter === true;
      return {
        ...col,
        enableSorting: isFunction || isTrue,
        sortingFn: isFunction ? col.sorter as SortingFn<TData> : isTrue ? () => 0 : undefined,
      };
    });
  }, [columns]);

  const table = useReactTable({
    ...tableProps,
    columns: processedColumns,
    initialState,
    pageCount,
    state: {
      pagination,
      sorting,
      columnFilters,
      columnVisibility,
      columnPinning: {
        right: ["actions"],
      },
    },
    defaultColumn: {
      ...tableProps.defaultColumn,
      enableColumnFilter: false,
      enableSorting: false,
    },
    onPaginationChange,
    onSortingChange,
    onColumnFiltersChange,
    onColumnVisibilityChange: setColumnVisibility,
    getCoreRowModel: getCoreRowModel(),
    getExpandedRowModel: getExpandedRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getPaginationRowModel: getPaginationRowModel(),
    getSortedRowModel: getSortedRowModel(),
    manualPagination: true,
    manualSorting: false,
    manualFiltering: true,
  });

  return { table };
};
