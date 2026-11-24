import React from "react";
import { Controller, useFormContext } from "react-hook-form";
import { Label } from "../ui/label";
import { RadioGroup, RadioGroupItem } from "../ui/radio-group";
import {
  Field,
  FieldDescription,
  FieldError,
  FieldLabel,
} from "@/components/ui/field";
import { cn } from "@/lib/utils";

interface MFieldRadioProps extends React.InputHTMLAttributes<HTMLInputElement> {
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

const MFieldRadio: React.FC<MFieldRadioProps> = ({
  name,
  label,
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
          <RadioGroup
            {...field}
            onValueChange={field.onChange}
            aria-invalid={fieldState.invalid}
            className={cn("w-fit flex gap-2", inputClassName)}
            disabled={props.disabled}
          >
            {options?.map(({ label, value }, index) => {
              const domId = `${name}-${value}-${index}`;
              return (
                <div
                  key={value}
                  className="flex items-center gap-1.5 px-2 py-1"
                >
                  <RadioGroupItem value={value} id={domId} className="" />
                  <Label
                    htmlFor={domId}
                    className="font-bold text-[16px] leading-[16px] peer-data-[state=checked]:text-primary cursor-pointer"
                  >
                    {label}
                  </Label>
                </div>
              );
            })}
          </RadioGroup>
          {postfix && <span className="font-normal text-base">{postfix}</span>}
          {description && <FieldDescription>{description}</FieldDescription>}
          {fieldState.invalid && <FieldError errors={[fieldState.error]} />}
        </Field>
      )}
    />
  );
};

export default MFieldRadio;
