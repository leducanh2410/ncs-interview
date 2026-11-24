import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/grinning_face.svg?react";

const GrinningFaceIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default GrinningFaceIcon;
