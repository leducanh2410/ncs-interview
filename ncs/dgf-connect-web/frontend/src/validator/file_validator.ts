import i18n from '@/locales/i18n';
import CommonHelper from '@/helpers/common.helper';
import AbstractValidator from '@/validator/abstract_validator';

export default class FileValidator<T> extends AbstractValidator<T> {
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

            // TODO: Add more validations here
            return null;
        };
    }
}
