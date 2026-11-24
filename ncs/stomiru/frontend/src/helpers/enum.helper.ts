import type { Enum } from "@/types/enum";

const EnumHelper = {
  getText(enumObj: Enum, value: string, isTrans?: boolean) {
    for (const key of Object.keys(enumObj)) {
      if (value === enumObj[key].value) {
        return `${!isTrans ? "" : "enum:"}${enumObj[key].label}`;
      }
    }
    return "";
  },
};

export default EnumHelper;
