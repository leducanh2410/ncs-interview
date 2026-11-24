import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/file_search.svg?react";

const FileSearchIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default FileSearchIcon;
