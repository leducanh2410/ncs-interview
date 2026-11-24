import * as React from "react";
import { format, parse } from "date-fns";
import type { DateRange } from "react-day-picker";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Calendar } from "@/components/ui/calendar";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import CalendarIcon from "../icons/calendar-icon";

interface DatePickerWithRangeProps {
  className?: string;
  value?: string;
  onChange?: (value: string | undefined) => void;
  placeholder?: string;
}

export function DatePickerWithRange({
  className,
  value,
  onChange,
  placeholder = "日付を選択",
}: DatePickerWithRangeProps) {
  const dateObj = React.useMemo(() => {
    if (!value) return undefined;
    const parts = value.split(",");

    // Check if the parts are actual dates before parsing
    const fromDate = parts[0] ? parse(parts[0], "yyyy-MM-dd", new Date()) : undefined;
    const toDate = parts[1] ? parse(parts[1], "yyyy-MM-dd", new Date()) : undefined;

    // Validate if the parsed dates are indeed valid dates using !isNaN
    return {
      from: fromDate && !isNaN(fromDate.getTime()) ? fromDate : undefined,
      to: toDate && !isNaN(toDate.getTime()) ? toDate : undefined,
    } as DateRange;
  }, [value]);

  const [date, setDate] = React.useState<DateRange | undefined>(dateObj);
  const [isFromOpen, setIsFromOpen] = React.useState(false);
  const [isToOpen, setIsToOpen] = React.useState(false);

  React.useEffect(() => {
    setDate(dateObj);
  }, [dateObj]);

  const triggerChange = (newFrom?: Date, newTo?: Date) => {
    if (!newFrom && !newTo) {
      onChange?.(undefined);
    } else {
      const fromStr = newFrom ? format(newFrom, "yyyy-MM-dd") : "";
      const toStr = newTo ? format(newTo, "yyyy-MM-dd") : "";
      onChange?.(`${fromStr},${toStr}`);
    }
  };

  const handleFromSelect = (newDate: Date | undefined) => {
    const newDateObj = { from: newDate, to: date?.to };
    setDate(newDateObj);
    setIsFromOpen(false);
    triggerChange(newDateObj.from, newDateObj.to);
  };

  const handleToSelect = (newDate: Date | undefined) => {
    const newDateObj = { from: date?.from, to: newDate };
    setDate(newDateObj);
    setIsToOpen(false);
    triggerChange(newDateObj.from, newDateObj.to);
  };

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <span className="text-sm font-bold text-nowrap">{placeholder}</span>
      <Popover open={isFromOpen} onOpenChange={setIsFromOpen}>
        <PopoverTrigger asChild>
          <Button
            variant={"outline"}
            className={cn(
              "p-2 text-left font-normal rounded-[8px] text-base leading-[22px] justify-between ring-0! border-input h-[38px] min-w-50",
              !date?.from && "text-muted-foreground"
            )}
          >
            {date?.from ? format(date.from, "yyyy/MM/dd") : <span>年/月/日</span>}
            <CalendarIcon svgClassName="size-[20px]" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0 z-[100]" align="start">
          <Calendar
            autoFocus
            mode="single"
            defaultMonth={date?.from}
            selected={date?.from}
            onSelect={handleFromSelect}
          />
        </PopoverContent>
      </Popover>

      <span className="text-[#A1A1AA] font-bold">~</span>

      <Popover open={isToOpen} onOpenChange={setIsToOpen}>
        <PopoverTrigger asChild>
          <Button
            variant={"outline"}
            className={cn(
              "p-2 text-left font-normal rounded-[8px] text-base leading-[22px] justify-between ring-0! border-input h-[38px] min-w-50",
              !date?.to && "text-muted-foreground"
            )}
          >
            {date?.to ? format(date.to, "yyyy/MM/dd") : <span>年/月/日</span>}
            <CalendarIcon svgClassName="size-[20px]" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-auto p-0 z-[100]" align="start">
          <Calendar
            autoFocus
            mode="single"
            defaultMonth={date?.to}
            selected={date?.to}
            onSelect={handleToSelect}
          />
        </PopoverContent>
      </Popover>
    </div>
  );
}
