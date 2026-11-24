import { type MouseEventHandler, type CSSProperties } from "react";
import { cn } from "@/lib/utils";

export type SvgIconProps = {
  size?: number;
  className?: string;
  style?: CSSProperties;
  onClick?: MouseEventHandler<HTMLElement>;
  width?: number;
  height?: number;
  svgClassName?: string;
};

const SvgIcon = ({
  svg: SvgComponent,
  size = 18,
  width,
  height,
  className,
  onClick,
  svgClassName,
  ...props
}: { svg: any } & SvgIconProps) => {
  const svgSize = size;
  return (
    <span
      role="img"
      className={cn("svg-icon flex", className)}
      onClick={onClick}
      {...props}
    >
      <SvgComponent
        width={`${width || svgSize}px`}
        height={`${height || svgSize}px`}
        className={svgClassName}
      />
    </span>
  );
};

export default SvgIcon;
