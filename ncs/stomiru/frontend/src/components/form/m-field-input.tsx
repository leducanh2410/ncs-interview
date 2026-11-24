import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface MFieldInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  fieldClassName?: string;
  postfix?: React.ReactNode;
}

const MFieldInput: React.FC<MFieldInputProps> = ({
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
          <Input
            {...field}
            id={field.name}
            aria-invalid={fieldState.invalid}
            placeholder={placeholder}
            className={cn("bg-white", inputClassName)}
            type={props.type ?? "text"}
            disabled={props.disabled}
            autoComplete={props.autoComplete}
          />
          {postfix && <span className="font-normal text-base">{postfix}</span>}
          {description && <FieldDescription>{description}</FieldDescription>}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default MFieldInput;
