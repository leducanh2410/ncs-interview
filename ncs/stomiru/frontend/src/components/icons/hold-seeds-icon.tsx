import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/hold-seeds.svg?react";

const HoldSeedsIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default HoldSeedsIcon;
