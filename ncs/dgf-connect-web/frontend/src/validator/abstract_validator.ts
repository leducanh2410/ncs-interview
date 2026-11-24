import i18n from '@/locales/i18n';

export interface ValidationBuilder<T> {
    getValidation(): (value: T, values: Record<string, T>, field: string) => string | null;
}

export default abstract class AbstractValidator<T> implements ValidationBuilder<T> {
    protected _label?: string;
    protected _required: boolean = false;
    protected _requiredIf?: (values: Record<string, T>) => boolean;
    protected _isSelect: boolean = false;

    label(label: string) {
        this._label = label;
        return this;
    }

    required() {
        this._required = true;
        return this;
    }

    requiredIf(callback: (values: Record<string, T>) => boolean) {
        this._requiredIf = callback;
        return this;
    }

    isSelect() {
        this._isSelect = true;
        return this;
    }

    protected getFieldName(field: string) {
        return i18n.t(`field|${this._label || field}`);
    }

    protected isRequired(values: Record<string, T>) {
        if (this._required) {
            return true;
        }
        if (this._requiredIf) {
            return this._requiredIf(values);
        }
        return false;
    }

    abstract getValidation(): (value: T, values: Record<string, T>, field: string) => string | null;
}
