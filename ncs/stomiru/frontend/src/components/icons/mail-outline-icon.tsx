import SvgIcon, { type SvgIconProps } from "@/components/svg-icon";
import Svg from "@/assets/icons/mail_outline.svg?react";

const MailOutlineIcon = (props: SvgIconProps) => (
  <SvgIcon svg={Svg} {...props} />
);

export default MailOutlineIcon;
