import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/timeline.svg?react";
const TimelineIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default TimelineIcon;
