export type ParamType = string | number | string[] | number[] | boolean | Date | Date[] | null;

export type FormTransformConfig = Record<
    string,
    {
        field?: string;
        isDate?: boolean;
        isBoolean?: boolean;
        isMonth?: boolean;
        isArray?: boolean;
    }
>;
