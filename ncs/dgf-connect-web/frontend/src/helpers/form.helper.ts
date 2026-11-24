import { UseFormReturnType } from '@mantine/form';
import { ParsedUrlQuery } from 'querystring';
import i18n from '@/locales/i18n';
import { FormTransformConfig, ParamType } from '@/types/form.d';
import { ApiValidationError } from '@/helpers/api.helper';
import DateHelper, { DATE_STORE_FORMAT, DATE_STORE_FORMAT_MONTH } from '@/helpers/date.helper';

const FormHelper = {
    queryToForm: (query: ParsedUrlQuery, config: FormTransformConfig = {}) => {
        const formData: Record<string, ParamType> = {};
        for (let field of Object.keys(query)) {
            let value = query[field];
            if (value === undefined || value === '') {
                continue;
            }
            const conf = config[field];
            if (!conf) {
                formData[field] = value;
                continue;
            }
            if (conf.field) {
                field = conf.field;
            }
            if (conf.isBoolean) {
                formData[field] = value === 'true';
                continue;
            }
            if (conf.isArray && !Array.isArray(value)) {
                value = [value];
            }
            if (Array.isArray(value)) {
                if (conf.isDate || conf.isMonth) {
                    const tmp: Date[] = [];
                    for (const v of value) {
                        const format = conf.isDate ? DATE_STORE_FORMAT : DATE_STORE_FORMAT_MONTH;
                        const date = DateHelper.parse(v, format);
                        if (date) {
                            tmp.push(date.toDate());
                        }
                    }
                    formData[field] = tmp;
                } else {
                    formData[field] = value;
                }
            } else {
                if (conf.isDate || conf.isMonth) {
                    const format = conf.isDate ? DATE_STORE_FORMAT : DATE_STORE_FORMAT_MONTH;
                    const date = DateHelper.parse(value, format);
                    formData[field] = date ? date.toDate() : null;
                } else {
                    formData[field] = value;
                }
            }
        }
        return formData;
    },
    toParams: (values: Record<string, ParamType>, configs: FormTransformConfig = {}) => {
        const params: ParsedUrlQuery = {};
        for (let field of Object.keys(values)) {
            const value = values[field];
            const config = configs[field];
            if (!config || value === null) {
                params[field] = value as any;
                continue;
            }
            if (config.field) {
                field = config.field;
            }
            if (config.isBoolean && value) {
                params[field] = 'true';
                continue;
            }
            if (Array.isArray(value)) {
                params[field] = value.map((v) => {
                    if (config.isDate) {
                        return DateHelper.parseAndFormat(v as Date, '', DATE_STORE_FORMAT);
                    } else if (config.isMonth) {
                        return DateHelper.parseAndFormat(v as Date, '', DATE_STORE_FORMAT_MONTH);
                    }
                    return v as string;
                });
            } else {
                if (config.isDate) {
                    params[field] = DateHelper.parseAndFormat(value as Date, '', DATE_STORE_FORMAT);
                } else if (config.isMonth) {
                    params[field] = DateHelper.parseAndFormat(value as Date, '', DATE_STORE_FORMAT_MONTH);
                }
            }
        }
        return params;
    },
    setFormError(form: UseFormReturnType<any, any>, error: unknown) {
        if (error instanceof ApiValidationError) {
            for (const key of Object.keys(error.data)) {
                form.setFieldError(key, i18n.t(`validation|${error.data[key][0]}`));
            }
        }
    },
};

export default FormHelper;
