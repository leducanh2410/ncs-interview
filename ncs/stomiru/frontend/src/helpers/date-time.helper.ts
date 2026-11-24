import { format } from "date-fns";

const DateTimeHelper = {
  format: (str?: string | Date, toFormat = "yyyy/MM/dd") => {
    if (!str) return str;
    return format(new Date(str), toFormat);
  },
  currentYear: () => {
    return new Date().getFullYear();
  },
};

export default DateTimeHelper;
