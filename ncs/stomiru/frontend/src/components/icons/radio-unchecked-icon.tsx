import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/radio_unchecked.svg?react";

const RadioUncheckedIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default RadioUncheckedIcon;
