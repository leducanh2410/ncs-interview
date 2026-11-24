import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/close.svg?react";

const CloseIcon = (props: SvgIconProps) => <SvgIcon svg={Svg} {...props} />;

export default CloseIcon;
