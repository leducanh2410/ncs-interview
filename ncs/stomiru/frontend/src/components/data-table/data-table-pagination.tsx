import type { ComponentProps } from "react";
import type { Table } from "@tanstack/react-table";
import {
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
} from "@/components/ui/pagination";
import { cn } from "@/lib/utils";

type DataTablePaginationProps<TData> = ComponentProps<"div"> & {
  table: Table<TData>;
  pageSizeOptions?: number[];
};

export const DataTablePagination = <TData,>({
  table,
  // pageSizeOptions = [10, 25, 50, 100],
  className,
  ...props
}: DataTablePaginationProps<TData>) => {
  const pageCount = Number(table.getPageCount()) || 0;
  
  const renderPages = () => {
    const pages = [];
    const currentPageIndex = isNaN(Number(table.getState().pagination?.pageIndex)) 
                              ? 0 
                              : Number(table.getState().pagination?.pageIndex);
    const currentPage = currentPageIndex + 1;

    const renderPageItem = (pageNumber: number) => (
      <PaginationItem key={pageNumber}>
        <PaginationLink
          isActive={currentPageIndex === pageNumber - 1}
          onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
            e.preventDefault();
            table.setPageIndex(pageNumber - 1);
          }}
        >
          {pageNumber}
        </PaginationLink>
      </PaginationItem>
    );

    const renderEllipsis = (key: string) => (
      <PaginationItem key={key}>
        <PaginationEllipsis />
      </PaginationItem>
    );

    if (pageCount <= 7) {
      for (let i = 1; i <= pageCount; i++) {
        pages.push(renderPageItem(i));
      }
    } else {
      if (currentPage <= 4) {
        // 1 2 3 4 5 ... max
        for (let i = 1; i <= 5; i++) {
          pages.push(renderPageItem(i));
        }
        pages.push(renderEllipsis("ellipsis-end"));
        pages.push(renderPageItem(pageCount));
      } else if (currentPage >= pageCount - 3) {
        // 1 ... max-4 max-3 max-2 max-1 max
        pages.push(renderPageItem(1));
        pages.push(renderEllipsis("ellipsis-start"));
        for (let i = pageCount - 4; i <= pageCount; i++) {
          pages.push(renderPageItem(i));
        }
      } else {
        // 1 ... curr-1 curr curr+1 ... max
        pages.push(renderPageItem(1));
        pages.push(renderEllipsis("ellipsis-start"));
        pages.push(renderPageItem(currentPage - 1));
        pages.push(renderPageItem(currentPage));
        pages.push(renderPageItem(currentPage + 1));
        pages.push(renderEllipsis("ellipsis-end"));
        pages.push(renderPageItem(pageCount));
      }
    }

    return pages;
  };

  if (pageCount <= 1) return null;

  const currentIndex = isNaN(Number(table.getState().pagination?.pageIndex)) 
                        ? 0 
                        : Number(table.getState().pagination?.pageIndex);
  const isPreviousDisabled = currentIndex <= 0;
  const isNextDisabled = currentIndex >= pageCount - 1;

  return (
    <Pagination className={cn("pt-4 pb-2", className)} {...props}>
      <PaginationContent>
        <PaginationItem>
          <PaginationPrevious
            onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
              e.preventDefault();
              if (isPreviousDisabled) return;
              if (currentIndex > 0)
                table.setPageIndex(currentIndex - 1);
            }}
            aria-disabled={isPreviousDisabled}
            tabIndex={isPreviousDisabled ? -1 : undefined}
            className={
              isPreviousDisabled
                ? "pointer-events-none opacity-40 cursor-not-allowed"
                : "cursor-pointer"
            }
          />
        </PaginationItem>
        {renderPages()}
        <PaginationItem>
          <PaginationNext
            onClick={(e: React.MouseEvent<HTMLAnchorElement>) => {
              e.preventDefault();
              if (isNextDisabled) return;
              if (currentIndex < pageCount - 1) {
                table.setPageIndex(currentIndex + 1);
              }
            }}
            aria-disabled={isNextDisabled}
            tabIndex={isNextDisabled ? -1 : undefined}
            className={
              isNextDisabled
                ? "pointer-events-none opacity-40 cursor-not-allowed"
                : "cursor-pointer"
            }
          />
        </PaginationItem>
      </PaginationContent>
    </Pagination>
  );
};
