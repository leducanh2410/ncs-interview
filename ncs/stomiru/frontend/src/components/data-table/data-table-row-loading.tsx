import { useEffect, useState } from "react";
import { LoaderCircle } from "@/components/custom/loader";
import { TableCell, TableRow } from "@/components/ui/table";
import { cn } from "@/lib/utils";

interface Props {
  isLoading?: boolean;
  colSpan: number;
}

export const DataTableRowLoading = (
  { isLoading, colSpan }: Props,
  colSpan1: number = colSpan
) => {
  const [show, setShow] = useState(false);

  useEffect(() => {
    if (isLoading) {
      setShow(true);
    } else {
      const t = setTimeout(() => setShow(false), 300);
      return () => clearTimeout(t);
    }
  }, [isLoading]);

  if (!show && !isLoading) return null;

  return (
    <TableRow
      className={cn(
        "absolute inset-0 bg-gray-50! rounded z-50 top-8 transition-opacity duration-300 border-transparent !border-none",
        isLoading ? "opacity-100" : "opacity-0"
      )}
    >
      <TableCell
        colSpan={colSpan1}
        className="text-center !w-full h-full flex items-center justify-center border-0"
      >
        <span className="flex justify-center items-center">
          <LoaderCircle />
        </span>
      </TableCell>
    </TableRow>
  );
};
