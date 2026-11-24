import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/like.svg?react";
const LikeIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);
export default LikeIcon;
