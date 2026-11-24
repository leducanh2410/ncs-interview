import { ValidationBuilder } from '@/validator/abstract_validator';
import ArrayValidator from '@/validator/array_validator';
import DateValidator from '@/validator/date_validator';
import FileValidator from '@/validator/file_validator';
import NumberValidator from '@/validator/number_validator';
import StringValidator from '@/validator/string_validator';

export type ValidationFunc<T> = (value: T, values: Record<string, T>, field: string) => string | null;

export const validatorResolver = <T>(
    schema: Record<string, ValidationBuilder<T>>,
): Record<string, ValidationFunc<T>> => {
    const result: Record<string, ValidationFunc<T>> = {};
    for (const key in schema) {
        result[key] = schema[key].getValidation();
    }

    return result;
};

export const Validator = {
    string: <T>() => new StringValidator<T>(),
    number: <T>() => new NumberValidator<T>(),
    date: <T>() => new DateValidator<T>(),
    array: <T>() => new ArrayValidator<T>(),
    file: <T>() => new FileValidator<T>(),
};
