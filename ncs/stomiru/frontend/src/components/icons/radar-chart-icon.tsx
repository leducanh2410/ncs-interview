import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/radar_chart.svg?react";

const RadarChartIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default RadarChartIcon;
