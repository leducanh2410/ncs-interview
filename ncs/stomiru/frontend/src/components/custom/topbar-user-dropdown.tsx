import type { ReactNode } from "react";
import EmotionHappyIcon from "../icons/emotion-happy-icon";
import KeyboardArrowDownIcon from "../icons/keyboard-arrow-down-icon";
import { Button } from "../ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "../ui/dropdown-menu";
import { cn } from "@/lib/utils";

interface TopbarUserDropdownProps {
  iconClassName?: string;
  label?: string;
  menuItems?: {
    label?: ReactNode;
    onClick?: () => void;
    separator?: boolean;
  }[];
}

export const TopbarUserDropdown = ({
  iconClassName,
  label,
  menuItems,
}: TopbarUserDropdownProps) => {
  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button
          variant="ghost"
          className="h-auto min-h-8 px-0 bg-transparent! min-w-[unset] justify-end outline-none! ring-0! border-transparent! py-1"
        >
          <div className="gap-2 flex items-center text-left">
            <EmotionHappyIcon
              svgClassName="size-7"
              className={cn("text-white shrink-0", iconClassName)}
            />
            <span className="font-medium text-[16px] leading-[19px] truncate max-w-[300px]">
              {label}
            </span>
            <KeyboardArrowDownIcon size={32} svgClassName="size-8 shrink-0" />
          </div>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        align="end"
        className="rounded-[10px] shadow-[0px_4px_14px_0px_rgba(0,0,0,0.08)] ring-0 p-0 py-1 min-w-[192px]"
      >
        {menuItems?.map((item, index) =>
          item.separator ? (
            <DropdownMenuSeparator />
          ) : (
            <DropdownMenuItem
              key={index}
              className="h-9 rounded-none p-2 text-[14px] leading-[20px]"
              onClick={item.onClick}
            >
              {item.label}
            </DropdownMenuItem>
          )
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
};

export default TopbarUserDropdown;
