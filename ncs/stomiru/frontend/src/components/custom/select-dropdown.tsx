import { LoaderIcon } from "lucide-react";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { cn } from "@/lib/utils";

interface SelectDropdownProps {
  onValueChange?: (value: string) => void;
  defaultValue: string | undefined;
  placeholder?: string;
  isPending?: boolean;
  options: { label: string; value: string }[] | undefined;
  disabled?: boolean;
  className?: string;
  isControlled?: boolean;
  allValue?: string;
}

export function SelectDropdown({
  defaultValue,
  onValueChange,
  isPending,
  options,
  placeholder,
  disabled,
  className = "",
  isControlled = false,
  allValue,
}: SelectDropdownProps) {
  const defaultState = isControlled
    ? { value: defaultValue, onValueChange }
    : { defaultValue, onValueChange };
  return (
    <Select {...defaultState}>
      <SelectTrigger
        disabled={disabled}
        className={cn("h-[38px]! rounded-[6px]", className)}
      >
        <SelectValue placeholder={placeholder ?? "Select"} />
      </SelectTrigger>
      <SelectContent className="rounded-[6px]">
        {isPending ? (
          <SelectItem disabled value="loading" className="h-14">
            <div className="flex items-center justify-center gap-2">
              <LoaderIcon className="h-5 w-5 animate-spin" />
              {"  "}
              Loading...
            </div>
          </SelectItem>
        ) : (
          <>
            {!!allValue && (
              <SelectItem value={null} className="min-w-full h-[38px]!">
                {allValue}
              </SelectItem>
            )}
            {options?.map(({ label, value }) => (
              <SelectItem key={value} value={value} className="h-[38px]!">
                {label}
              </SelectItem>
            ))}
          </>
        )}
      </SelectContent>
    </Select>
  );
}
