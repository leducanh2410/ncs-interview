import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/healthy_recognition.svg?react";

const HealthyRecognitionIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default HealthyRecognitionIcon;
