import { useCallback } from "react";
import { useNavigate } from "@tanstack/react-router";
import ArrowBackIcon from "@/components/icons/arrow-back-icon";
import { Button } from "@/components/ui/button";
import { useTranslation } from "@/hooks/use-translation";
import { cn } from "@/lib/utils";

interface BackButtonProps {
  href?: string;
  onClick?: () => void;
  label?: string;
  className?: string;
}

const BASE_CLASS_NAME =
  "flex items-center gap-1 text-base text-black underline hover:text-[#00a429] hover:no-underline font-normal px-0 bg-transparent!";

export function BackButton({
  href,
  onClick,
  label,
  className,
}: BackButtonProps) {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const shouldHandleCustomClick = Boolean(href || onClick);

  const handleClick = useCallback(() => {
    if (onClick) {
      onClick();
      return;
    }

    if (href) {
      navigate({ to: href });
    }
  }, [href, navigate, onClick]);

  return (
    <Button
      variant="ghost"
      isBack={!href && !onClick}
      onClick={shouldHandleCustomClick ? handleClick : undefined}
      className={cn(BASE_CLASS_NAME, className)}
    >
      <ArrowBackIcon size={24} svgClassName="size-6" />
      {label ?? t("common.back")}
    </Button>
  );
}

export default BackButton;
