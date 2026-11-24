import { ValidationFunc, Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import { AnnouncementWithRelation } from '@/types/models/announcement';
import { getTranslationLocales } from '@/enums/locale.enum';
import { ValidationBuilder } from '@/validator/abstract_validator';

const translationLocales = getTranslationLocales();

const formInitialValue: Record<string, ParamType> = {
    title: '',
    content: '',
    workplace_ids: [],
    start_at: null,
    end_at: null,
    is_active: true,
};

const schema: Record<string, ValidationBuilder<ParamType>> = {
    title: Validator.string().required().maxLength(255),
    workplace_ids: Validator.array().required().minLength(1),
    start_at: Validator.string().required().isSelect(),
};

for (const locale of translationLocales) {
    formInitialValue[`translated_${locale}`] = false;
    formInitialValue[`title_${locale}`] = '';
    formInitialValue[`content_${locale}`] = '';

    schema[`title_${locale}`] = Validator.string()
        .maxLength(255)
        .requiredIf((values) => values[`translated_${locale}`] as boolean);
}

const stripHtml = (html: string): string =>
    html
        .replace(/<[^>]*>/g, ' ')
        .replace(/&(nbsp|ensp|emsp|thinsp|#160|#8194|#8195|#8201|#xa0);/gi, ' ')
        .replace(/[\s\u00A0]+/g, '')
        .trim();

const isEmptyContent = (value: ParamType): boolean => typeof value !== 'string' || stripHtml(value).length === 0;

const requiredContentValidation =
    (field: string, isRequired: (values: Record<string, ParamType>) => boolean): ValidationFunc<ParamType> =>
    (value, values) => {
        if (!isRequired(values)) {
            return null;
        }
        if (isEmptyContent(value)) {
            return i18n.t('validation|Please enter {{field}}', { field: i18n.t(`field|${field}`) });
        }
        return null;
    };

const endAtValidation: ValidationFunc<ParamType> = (value, values) => {
    if (typeof value !== 'string' || value.length === 0) {
        return i18n.t('validation|Please select {{field}}', { field: i18n.t('field|end_at') });
    }
    if (typeof values.start_at === 'string' && values.start_at.length > 0 && value <= values.start_at) {
        return i18n.t('validation|End time must be after start time');
    }
    return null;
};

const buildValidation = (): Record<string, ValidationFunc<ParamType>> => {
    const validation = validatorResolver(schema);
    validation.content = requiredContentValidation('content', () => true);
    validation.end_at = endAtValidation;
    for (const locale of translationLocales) {
        validation[`content_${locale}`] = requiredContentValidation(
            `content_${locale}`,
            (values) => values[`translated_${locale}`] as boolean,
        );
    }
    return validation;
};

const toFormValues = (announcement: AnnouncementWithRelation): Record<string, ParamType> => {
    const values: Record<string, ParamType> = {
        ...formInitialValue,
        title: announcement.title,
        content: announcement.content,
        workplace_ids: announcement.workplaces.map((workplace) => workplace.id.toString()),
        start_at: announcement.start_at,
        end_at: announcement.end_at,
        is_active: announcement.is_active,
    };
    for (const translation of announcement.translations) {
        values[`translated_${translation.lang}`] = true;
        values[`title_${translation.lang}`] = translation.title;
        values[`content_${translation.lang}`] = translation.content;
    }
    return values;
};

type BuildFormDataOptions = {
    image: File | null;
    removeImage: boolean;
    isUpdate: boolean;
};

const buildFormData = (values: Record<string, ParamType>, options: BuildFormDataOptions): FormData => {
    const formData = new FormData();
    formData.append('title', values.title as string);
    formData.append('content', values.content as string);
    for (const workplaceId of values.workplace_ids as string[]) {
        formData.append('workplace_ids[]', workplaceId);
    }
    formData.append('start_at', values.start_at as string);
    formData.append('end_at', values.end_at as string);
    formData.append('is_active', values.is_active ? '1' : '0');
    for (const locale of translationLocales) {
        if (!values[`translated_${locale}`]) {
            continue;
        }
        formData.append(`title_${locale}`, values[`title_${locale}`] as string);
        formData.append(`content_${locale}`, values[`content_${locale}`] as string);
    }
    if (options.image) {
        formData.append('image', options.image);
    } else if (options.removeImage) {
        formData.append('remove_image', '1');
    }
    if (options.isUpdate) {
        formData.append('_method', 'PUT');
    }
    return formData;
};

export { formInitialValue, buildValidation, toFormValues, buildFormData, isEmptyContent, translationLocales };
