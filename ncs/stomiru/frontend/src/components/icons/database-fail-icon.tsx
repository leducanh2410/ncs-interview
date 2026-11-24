import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/database_fail.svg?react";

const DatabaseFailIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default DatabaseFailIcon;
