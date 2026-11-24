import { Tooltip, TooltipContent, TooltipTrigger } from "../ui/tooltip";
import { cn } from "@/lib/utils";

interface MTooltipProps {
  content: React.ReactNode;
  children: React.ReactNode;
  contentClassName?: string;
  side?: "top" | "bottom" | "left" | "right";
}

export const MTooltip = ({
  content,
  children,
  contentClassName,
  side,
}: MTooltipProps) => {
  return (
    <Tooltip>
      <TooltipTrigger asChild>{children}</TooltipTrigger>
      <TooltipContent
        className={cn(
          "px-3 py-1.5 font-bold text-[14px] leading-[16px] rounded-[8px] opacity-80",
          contentClassName
        )}
        side={side}
      >
        {content}
      </TooltipContent>
    </Tooltip>
  );
};
