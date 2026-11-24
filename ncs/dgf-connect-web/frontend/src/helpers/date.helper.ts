import dayjs, { Dayjs } from 'dayjs';
import en from 'dayjs/locale/en';
import ja from 'dayjs/locale/ja';
import vi from 'dayjs/locale/vi';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import localeData from 'dayjs/plugin/localeData';
import timezone from 'dayjs/plugin/timezone';
import utc from 'dayjs/plugin/utc';
import i18n from 'i18next';

export const DATE_STORE_FORMAT = 'YYYY-MM-DD';

export const DATE_STORE_FORMAT_WITH_TIME = 'YYYY-MM-DD HH:mm:ss';
export const DATE_STORE_FORMAT_MONTH = 'YYYY-MM';

export const TIME_ZONE = 'Asia/Tokyo';

const DAYJS_LOCALES: Record<string, ILocale> = { en, vi, ja };

export const resolveDateLocale = (language?: string): string => {
    const code = (language ?? i18n.resolvedLanguage ?? i18n.language ?? 'en').split(/[-_]/)[0];
    return code in DAYJS_LOCALES ? code : 'en';
};

const displayFormat = () => i18n.t('FORMAT_DATE');

const DateHelper = {
    initDayjs: (language?: string) => {
        dayjs.extend(customParseFormat);
        dayjs.extend(localeData);
        dayjs().localeData();
        dayjs.extend(utc);
        dayjs.extend(timezone);

        Object.values(DAYJS_LOCALES).forEach((locale) => {
            dayjs.locale({ ...locale, weekStart: 1 }, undefined, true);
        });

        dayjs.locale(resolveDateLocale(language));
        dayjs.tz.setDefault(TIME_ZONE);
    },
    parse: (value: string, fromFormat: string | null = DATE_STORE_FORMAT, strict = true) => {
        let date = fromFormat ? dayjs(value, fromFormat, strict) : dayjs(value);
        if (!date.isValid()) {
            return null;
        }
        return date.tz(TIME_ZONE, true);
    },
    parseHour: (value: string, withSecond = false) => {
        const format = withSecond ? 'HH:mm:ss' : 'HH:mm';
        const now = DateHelper.now();
        return DateHelper.parse(value, format)
            ?.set('date', now.date())
            .set('month', now.month())
            .set('year', now.year());
    },
    parseAndFormat: (
        value: Date | string | null | undefined,
        fromFormat: string | null = DATE_STORE_FORMAT,
        toFormat: string = displayFormat(),
        valueIfInvalid: string = '-',
        strict = true,
    ) => {
        if (!value) return valueIfInvalid;
        const date = value instanceof Date ? dayjs(value).tz() : DateHelper.parse(value, fromFormat, strict);
        if (!date) return valueIfInvalid;
        return date.format(toFormat);
    },
    format: (value: Date | Dayjs, format: string = displayFormat()) => {
        if (value instanceof Date) {
            return dayjs(value).tz().format(format);
        }
        return value.format(format);
    },
    getDayOfWeek: (day: number): string => {
        return dayjs().day(day).format('dddd');
    },
    now: () => {
        return dayjs().tz();
    },
    formatTo25H: (value: string) => {
        // Split by HH:mm
        const parts = value.split(':');
        if (parts.length < 2) {
            return value;
        }
        const hour = parseInt(parts[0], 10);

        // Convert to 25H format
        return `${String(hour + 24).padStart(2, '0')}:${parts[1]}`;
    },
    formatWorkHour: (
        startTime: string,
        endTime: string,
        startBreakTime: string | null = null,
        endBreakTime: string | null = null,
    ) => {
        if (endTime > startTime) {
            return {
                startTime,
                endTime,
                startBreakTime,
                endBreakTime,
            };
        }
        const newEndTime = DateHelper.formatTo25H(endTime);
        let newStartBreakTime = startBreakTime;
        let newEndBreakTime = endBreakTime;
        if (newStartBreakTime && newEndBreakTime) {
            if (newStartBreakTime < startTime) {
                newStartBreakTime = DateHelper.formatTo25H(newStartBreakTime);
            }
            if (newEndBreakTime < startTime) {
                newEndBreakTime = DateHelper.formatTo25H(newEndBreakTime);
            }
        }

        return {
            startTime,
            endTime: newEndTime,
            startBreakTime: newStartBreakTime,
            endBreakTime: newEndBreakTime,
        };
    },
    formatMinuteToHour: (minute: number) => {
        return Math.floor(minute / 60) + ':' + (minute % 60).toString().padStart(2, '0');
    },
};

export default DateHelper;
