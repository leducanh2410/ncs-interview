import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import { Field, FieldDescription, FieldError, FieldLabel } from "@/components/ui/field";
import { InputPassword } from "@/components/ui/input-password";
import { cn } from "@/lib/utils";

interface InputPasswordFieldProps extends React.InputHTMLAttributes<HTMLInputElement> {
  name: string;
  label?: string;
  description?: string;
  isOptional?: boolean;
  inputClassName?: string;
  labelClassName?: string;
  subTitle?: string;
}

const InputPasswordField: React.FC<InputPasswordFieldProps> = ({
  name,
  label,
  placeholder,
  description,
  inputClassName,
  labelClassName,
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
            <span className="font-normal font-[16px] leading-[22px] opacity-80">
              {subTitle}
            </span>
          )}
          <InputPassword
            {...field}
            id={field.name}
            aria-invalid={fieldState.invalid}
            placeholder={placeholder}
            className={cn("bg-white text-base leading-[22px]", inputClassName)}
            disabled={props.disabled}
            autoComplete={props.autoComplete}
          />
          {description && (
            <FieldDescription className="text-black">
              {description}
            </FieldDescription>
          )}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default InputPasswordField;
