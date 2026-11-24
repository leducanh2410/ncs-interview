import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "../ui/select";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { cn } from "@/lib/utils";

interface MFieldSelectProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  fieldClassName?: string;
  postfix?: React.ReactNode;
  options: {
    label: string;
    value: string;
  }[];
}

const MFieldSelect: React.FC<MFieldSelectProps> = ({
  name,
  label,
  placeholder,
  description,
  inputClassName,
  labelClassName,
  fieldClassName,
  postfix,
  options,
  ...props
}) => {
  const { control } = useFormContext();

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
          <Select
            {...field}
            aria-invalid={fieldState.invalid}
            onValueChange={(v) => {
              field.onChange(v);
            }}
          >
            <SelectTrigger
              className={cn("h-[38px]! rounded-[6px]", inputClassName)}
            >
              <SelectValue placeholder={placeholder ?? "Select"} />
            </SelectTrigger>
            <SelectContent className="rounded-[6px]">
              {options?.map(({ label, value }) => (
                <SelectItem key={value} value={value} className="h-[38px]!">
                  {label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {postfix && <span className="font-normal text-base">{postfix}</span>}
          {description && <FieldDescription>{description}</FieldDescription>}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default MFieldSelect;
