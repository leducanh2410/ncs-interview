import { useEffect, useState } from "react";
import SearchIcon from "@/components/icons/search-icon";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

interface MSearchBoxProps {
  value: string;
  onChange?: (value: string) => void;
  onSearch: (value: string) => void;
  placeholder?: string;
  className?: string;
}

const MSearchBox = ({
  value,
  onChange,
  onSearch,
  placeholder,
  className,
  ...props
}: MSearchBoxProps) => {
  const [localValue, setLocalValue] = useState(value);

  useEffect(() => {
    setLocalValue(value);
  }, [value]);

  return (
    <div className="relative">
      <Input
        {...props}
        placeholder={placeholder || "Search..."}
        value={localValue}
        onChange={(e) => {
          setLocalValue(e.target.value);
          onChange && onChange(e.target.value);
        }}
        onKeyDown={(e) => e.key === "Enter" && onSearch(localValue)}
        className={cn(
          "text-sm px-4 border rounded-[17px] w-[280px]",
          className
        )}
      />
      <Button
        variant="ghost"
        size="icon"
        className="absolute right-1 top-1/2 -translate-y-1/2"
        onClick={() => onSearch(localValue)}
      >
        <SearchIcon svgClassName="size-[20px]" />
      </Button>
    </div>
  );
};

export default MSearchBox;
