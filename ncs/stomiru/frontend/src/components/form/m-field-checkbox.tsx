import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import { Checkbox } from "../ui/checkbox";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { cn } from "@/lib/utils";

interface MFieldCheckboxProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  fieldClassName?: string;
  postfix?: React.ReactNode;
}

const MFieldCheckbox: React.FC<MFieldCheckboxProps> = ({
  name,
  label,
  placeholder,
  description,
  inputClassName,
  labelClassName,
  fieldClassName,
  postfix,
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
          <Checkbox
            {...field}
            checked={field.value}
            onCheckedChange={field.onChange}
            aria-invalid={fieldState.invalid}
          />
          {postfix && <span className="font-normal text-base">{postfix}</span>}
          {description && <FieldDescription>{description}</FieldDescription>}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default MFieldCheckbox;
