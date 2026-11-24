import { type ComponentProps, useState } from "react";
import { Eye, EyeOff } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

type InputPasswordProps = ComponentProps<"input">;

function InputPassword({ className, disabled, ...props }: InputPasswordProps) {
  const [showPassword, setShowPassword] = useState(false);
  return (
    <div className={cn("relative rounded-md", className)}>
      <Input
        disabled={disabled}
        autoComplete="off"
        {...props}
        type={showPassword ? "text" : "password"}
      />
      <Button
        type="button"
        size="icon"
        variant="ghost"
        disabled={disabled}
        className="text-muted-foreground absolute top-1/2 right-1 h-6 w-6 -translate-y-1/2 rounded-md"
        onClick={() => setShowPassword((prev) => !prev)}
      >
        {showPassword ? <Eye size={18} /> : <EyeOff size={18} />}
      </Button>
    </div>
  );
}

export { InputPassword };
