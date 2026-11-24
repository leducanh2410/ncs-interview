import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/keyboard_arrow_down.svg?react";

const KeyboardArrowDownIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default KeyboardArrowDownIcon;
