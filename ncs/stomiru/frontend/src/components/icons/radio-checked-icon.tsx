import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/radio_checked.svg?react";

const RadioCheckedIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default RadioCheckedIcon;
