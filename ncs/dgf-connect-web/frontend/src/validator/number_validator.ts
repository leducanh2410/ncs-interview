import i18n from '@/locales/i18n';
import CommonHelper from '@/helpers/common.helper';
import AbstractValidator from '@/validator/abstract_validator';

export default class NumberValidator<T> extends AbstractValidator<T> {
    protected _gt?: number;
    protected _gte?: number;
    protected _lt?: number;
    protected _lte?: number;
    protected _email?: boolean;
    protected _integer?: boolean;
    protected _needConfirmation?: boolean;

    gt(gt: number) {
        this._gt = gt;
        return this;
    }

    gte(gte: number) {
        this._gte = gte;
        return this;
    }

    lt(lt: number) {
        this._lt = lt;
        return this;
    }

    lte(lte: number) {
        this._lte = lte;
        return this;
    }

    integer() {
        this._integer = true;
        return this;
    }

    getValidation() {
        return (value: T, values: Record<string, T>, orgField: string): string | null => {
            const field = this.getFieldName(orgField);
            const isRequired = this.isRequired(values);
            if (CommonHelper.isNullOrUndefined(value) || (value as unknown) === '') {
                if (isRequired) {
                    return this._isSelect
                        ? i18n.t('validation|Please select {{field}}', { field })
                        : i18n.t('validation|Please enter {{field}}', { field });
                }

                // Skip all other validations if the field is not required
                return null;
            }

            if (!CommonHelper.isNumeric(value)) {
                return i18n.t('validation|{{field}} must be a number', { field });
            }

            if (this._integer && !Number.isInteger(value)) {
                return i18n.t('validation|{{field}} must be an integer', { field });
            }

            if (this._gt !== undefined && value <= this._gt) {
                return i18n.t('validation|Please enter a number greater than {{min}}', { min: this._gt });
            }

            if (this._gte !== undefined && value < this._gte) {
                return i18n.t('validation|Please enter a number greater than or equal to {{min}}', { min: this._gte });
            }

            if (this._lt !== undefined && value >= this._lt) {
                return i18n.t('validation|Please enter a number less than {{max}}', { max: this._lt });
            }

            if (this._lte !== undefined && value > this._lte) {
                return i18n.t('validation|Please enter a number less than or equal to {{max}}', { max: this._lte });
            }

            return null;
        };
    }
}
