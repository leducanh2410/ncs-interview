import i18n from '@/locales/i18n';
import { Enum } from '@/types/misc';

const EnumHelper = {
    toArray(enumObj: Enum, valueKey: string = 'value', textKey: string = 'text') {
        return Object.keys(enumObj).map((key) => ({
            [valueKey]: enumObj[key].value,
            [textKey]: enumObj[key].text,
        }));
    },
    getValues(enumObj: Enum) {
        return Object.values(enumObj).map((value) => value.value);
    },
    toSelectArray(enumObj: Enum, keys?: string[]) {
        const options = Object.keys(enumObj).map((key) => ({
            value: enumObj[key].value,
            label: i18n.t(`enum|${enumObj[key].text}`),
        }));
        if (!keys) return options;
        return options.filter((option) => keys.indexOf(option.value) > -1);
    },
    getText(enumObj: Enum, value: string) {
        for (const key of Object.keys(enumObj)) {
            if (value === enumObj[key].value) {
                return i18n.t(`enum|${enumObj[key].text}`);
            }
        }
        return '';
    },
    getColor(enumObj: Enum, value: string) {
        for (const key of Object.keys(enumObj)) {
            if (value === enumObj[key].value) {
                return enumObj[key].color || 'gray';
            }
        }
        return 'gray';
    },
};

export default EnumHelper;
