import type { ComponentType } from "react";
import { useNavigate } from "@tanstack/react-router";
import type { SvgIconProps } from "@/components/svg-icon";
import { Button } from "@/components/ui/button";

interface RowActionButtonProps {
  label: string;
  icon: ComponentType<SvgIconProps>;
  to?: string;
  search?: Record<string, string>;
}

export const RowActionButton = ({
  label,
  icon: Icon,
  to,
  search,
}: RowActionButtonProps) => {
  const navigate = useNavigate();

  return (
    <Button
      variant="link"
      className="text-[14px] leading-[17px] text-black font-normal underline gap-0.5 p-0"
      onClick={(e) => {
        e.stopPropagation();
        if (to) {
          void navigate({ to, search });
        }
      }}
    >
      <Icon svgClassName="size-[18px]" />
      <span>{label}</span>
    </Button>
  );
};
