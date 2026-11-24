import { ParsedUrlQuery } from 'querystring';

type KeyedObject<T, K extends keyof T> = {
    [key: string]: T;
};

type GroupedObject<T, K extends keyof T> = {
    [key: string]: T[];
};

const CommonHelper = {
    urlQueryToString: (query: ParsedUrlQuery) => {
        const parts = [];
        for (let field of Object.keys(query)) {
            let value = query[field];
            if (value === undefined) {
                continue;
            }
            if (Array.isArray(value)) {
                field = `${field}[]`;
                for (const v of value) {
                    parts.push(`${field}=${v}`);
                }
            } else {
                parts.push(`${field}=${value}`);
            }
        }
        return parts.join('&');
    },
    replaceUrlParam(url: string, params: Record<string, string | number>) {
        let newUrl: string = url;
        for (const key of Object.keys(params)) {
            newUrl = newUrl.replace(`:${key}`, params[key].toString());
        }
        return newUrl;
    },
    sortObjectByKey(obj: Record<string | number, any>, isDesc = false) {
        return Object.keys(obj)
            .sort((a, b) => {
                if (isDesc) {
                    return a < b ? 1 : -1;
                }
                return a > b ? 1 : -1;
            })
            .reduce(
                (acc, key) => {
                    acc[key] = obj[key];
                    return acc;
                },
                {} as Record<string | number, any>,
            );
    },
    arrayKeyBy<T, K extends keyof T>(array: T[], keyField: K): KeyedObject<T, K> {
        return array.reduce(
            (acc, item) => {
                const key = item[keyField];
                if (typeof key === 'string' || typeof key === 'number') {
                    acc[key] = item;
                } else {
                    throw new Error(`Key field "${String(keyField)}" must be a string or number.`);
                }
                return acc;
            },
            {} as KeyedObject<T, K>,
        );
    },
    arrayGroupBy<T, K extends keyof T>(array: T[], keyField: K): GroupedObject<T, K> {
        return array.reduce(
            (acc, item) => {
                const key = item[keyField];
                if (typeof key === 'string' || typeof key === 'number') {
                    if (!acc[key]) {
                        acc[key] = [];
                    }
                    acc[key].push(item);
                } else {
                    throw new Error(`Key field "${String(keyField)}" must be a string or number.`);
                }
                return acc;
            },
            {} as GroupedObject<T, K>,
        );
    },
    isNullOrUndefined(value: any): value is null | undefined {
        return value === null || value === undefined;
    },
    isString(value: any): value is string {
        return typeof value === 'string';
    },
    isNumeric(value: any): value is number {
        return typeof value === 'number';
    },
    formatNumber(value: number): string {
        return new Intl.NumberFormat().format(value);
    },
};

export default CommonHelper;
