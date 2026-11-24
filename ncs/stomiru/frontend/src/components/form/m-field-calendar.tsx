import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import type { Matcher } from "react-day-picker";
import CalendarIcon from "../icons/calendar-icon";
import { Button } from "../ui/button";
import { Calendar } from "../ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "../ui/popover";
import { format } from "date-fns";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { cn } from "@/lib/utils";

interface MFieldCalendarProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  fieldClassName?: string;
  postfix?: React.ReactNode;
  displayFormat?: string;
  disabledDate?: Matcher | Matcher[];
  maxDate?: Date;
}

const MFieldCalendar: React.FC<MFieldCalendarProps> = ({
  name,
  label,
  placeholder,
  description,
  inputClassName,
  labelClassName,
  fieldClassName,
  postfix,
  displayFormat,
  disabledDate,
  maxDate,
}) => {
  const { control } = useFormContext();
  const resolvedDisabledDate = React.useMemo<Matcher | Matcher[] | undefined>(() => {
    if (!maxDate) {
      return disabledDate;
    }

    const maxDateMatcher: Matcher = { after: maxDate };
    if (!disabledDate) {
      return maxDateMatcher;
    }

    return Array.isArray(disabledDate)
      ? [...disabledDate, maxDateMatcher]
      : [disabledDate, maxDateMatcher];
  }, [disabledDate, maxDate]);

  return (
    <Controller
      name={name}
      control={control}
      render={({ field, fieldState }) => (
        <Field
          //data-invalid={fieldState.invalid}
          className={cn("gap-2 w-fit", fieldClassName)}
          orientation="horizontal"
        >
          {label && (
            <FieldLabel
              htmlFor={field.name}
              className={cn(
                "text-base font-bold whitespace-nowrap",
                labelClassName
              )}
            >
              <span>{label}</span>
            </FieldLabel>
          )}
          <Popover
            onOpenChange={(open) => {
              if (!open) {
                field.onBlur();
              }
            }}
          >
            <PopoverTrigger asChild>
              <Button
                variant={"outline"}
                className={cn(
                  "p-2 text-left font-normal rounded-[8px] text-base leading-[22px] justify-between ring-0! border-input h-[38px]",
                  !field.value && "text-muted-foreground",
                  inputClassName
                )}
                aria-invalid={fieldState.invalid}
              >
                {field.value ? (
                  format(field.value, displayFormat || "yyyy/MM/dd")
                ) : (
                  <span>{placeholder ?? "Select a date"}</span>
                )}
                <CalendarIcon size={20} />
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
              <Calendar
                mode="single"
                selected={field.value}
                onSelect={(value) => {
                  if (value && maxDate && value > maxDate) {
                    field.onBlur();
                    return;
                  }

                  field.onChange(value);
                  field.onBlur();
                }}
                disabled={resolvedDisabledDate}
                defaultMonth={field.value ?? new Date()}
                autoFocus
                captionLayout="dropdown"
                startMonth={new Date(1900, 0)}
                endMonth={maxDate ?? new Date(2100, 11)}
              />
            </PopoverContent>
          </Popover>
          {postfix && <span className="font-normal text-base">{postfix}</span>}
          {description && <FieldDescription>{description}</FieldDescription>}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default MFieldCalendar;
