import React, { type ReactNode } from "react";
import { Controller, useFormContext } from "react-hook-form";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface InputFieldProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  fieldClassName?: string;
  orientation?: "vertical" | "horizontal" | "responsive";
  subTitle?: ReactNode;
}

const InputField: React.FC<InputFieldProps> = ({
  name,
  label,
  placeholder,
  description,
  inputClassName,
  labelClassName,
  fieldClassName,
  orientation,
  subTitle,
  ...props
}) => {
  const { control } = useFormContext();

  return (
    <Controller
      name={name}
      control={control}
      render={({ field, fieldState }) => (
        <Field
          // data-invalid={fieldState.invalid}
          className={cn(fieldClassName)}
          orientation={orientation}
        >
          {label && (
            <FieldLabel
              htmlFor={field.name}
              className={cn("font-bold", labelClassName)}
            >
              {label}
            </FieldLabel>
          )}
          {subTitle && (
            <span className="font-normal font-[16px] leading-[22px] opacity-80 flex flex-col">
              {subTitle}
            </span>
          )}
          <Input
            {...field}
            id={field.name}
            aria-invalid={fieldState.invalid}
            placeholder={placeholder}
            className={cn("bg-white text-base leading-[22px]", inputClassName)}
            type={props.type ?? "text"}
            disabled={props.disabled}
            autoComplete={props.autoComplete}
          />
          {description && <FieldDescription>{description}</FieldDescription>}
          {/* {fieldState.invalid && <FieldError errors={[fieldState.error]} />} */}
        </Field>
      )}
    />
  );
};

export default InputField;
