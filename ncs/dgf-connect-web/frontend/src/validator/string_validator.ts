import i18n from '@/locales/i18n';
import CommonHelper from '@/helpers/common.helper';
import AbstractValidator from '@/validator/abstract_validator';

export default class StringValidator<T> extends AbstractValidator<T> {
    protected _minLength?: number;
    protected _maxLength?: number;
    protected _email?: boolean;
    protected _url?: boolean;
    protected _needConfirmation?: boolean;

    minLength(min: number) {
        this._minLength = min;
        return this;
    }

    maxLength(max: number) {
        this._maxLength = max;
        return this;
    }

    email() {
        this._email = true;
        return this;
    }

    url() {
        this._url = true;
        return this;
    }

    needConfirmation() {
        this._needConfirmation = true;
        return this;
    }

    getValidation() {
        return (value: T, values: Record<string, T>, orgField: string): string | null => {
            const field = this.getFieldName(orgField);
            const isRequired = this.isRequired(values);
            if (CommonHelper.isNullOrUndefined(value)) {
                if (isRequired) {
                    return this._isSelect
                        ? i18n.t('validation|Please select {{field}}', { field })
                        : i18n.t('validation|Please enter {{field}}', { field });
                }

                // Skip all other validations if the field is not required
                return null;
            }

            if (!CommonHelper.isString(value)) {
                return i18n.t('validation|{{field}} must be a string', { field });
            }

            if (value.trim().length === 0 && isRequired) {
                return this._isSelect
                    ? i18n.t('validation|Please select {{field}}', { field })
                    : i18n.t('validation|Please enter {{field}}', { field });
            }

            if (this._minLength !== undefined && value.length < this._minLength) {
                return i18n.t('validation|Please enter at least {{min}} characters', { min: this._minLength });
            }

            if (this._maxLength !== undefined && value.length > this._maxLength) {
                return i18n.t('validation|Please enter at most {{max}} characters', { max: this._maxLength });
            }

            if (this._email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                return i18n.t('validation|Please enter a valid email address');
            }

            if (this._url && !/^https?:\/\/\S+$/i.test(value)) {
                return i18n.t('validation|Please enter a valid URL starting with http:// or https://');
            }

            if (this._needConfirmation) {
                const confirmField = `${orgField}_confirmation`;
                if (values[confirmField] !== value) {
                    return i18n.t('validation|The {{field}} confirmation does not match', { field });
                }
            }

            return null;
        };
    }
}
