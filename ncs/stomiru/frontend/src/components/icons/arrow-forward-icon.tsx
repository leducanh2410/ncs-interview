import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/arrow_forward.svg?react";

const ArrowForwardIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default ArrowForwardIcon;
