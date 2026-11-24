import i18n from '@/locales/i18n';
import CommonHelper from '@/helpers/common.helper';
import AbstractValidator from '@/validator/abstract_validator';

export default class ArrayValidator<T> extends AbstractValidator<T> {
    protected _itemValidator?: AbstractValidator<T>;
    protected _minLength?: number;
    protected _maxLength?: number;

    itemValidator(validator: AbstractValidator<T>) {
        this._itemValidator = validator;
        return this;
    }

    minLength(min: number) {
        this._minLength = min;
        return this;
    }

    maxLength(max: number) {
        this._maxLength = max;
        return this;
    }

    getValidation() {
        return (value: T, values: Record<string, T>, orgField: string): string | null => {
            const field = this.getFieldName(orgField);
            const isRequired = this.isRequired(values);
            if (CommonHelper.isNullOrUndefined(value)) {
                if (isRequired) {
                    return i18n.t('validation|Please select {{field}}', { field });
                }

                // Skip all other validations if the field is not required
                return null;
            }
            if (!Array.isArray(value)) {
                return i18n.t('validation|{{field}} must be an array', { field });
            }

            if (this._minLength !== undefined && value.length < this._minLength) {
                return i18n.t('validation|Please select at least {{min}} items', { min: this._minLength });
            }

            if (this._maxLength !== undefined && value.length > this._maxLength) {
                return i18n.t('validation|Please select at most {{max}} items', { max: this._maxLength });
            }

            if (this._itemValidator) {
                const validation = this._itemValidator.getValidation();
                for (const v of value) {
                    if (validation(v, values, orgField)) {
                        return i18n.t('validation|Invalid item in {{field}}', { field });
                    }
                }
            }

            return null;
        };
    }
}
