import { Validator, validatorResolver } from '@/validator';
import i18n from '@/locales/i18n';
import { ParamType } from '@/types/form';
import { DEFAULT_NEARBY_RADIUS_KM, GenderTargetEnum, MAX_NEARBY_RADIUS_KM } from '@/enums/gender_target.enum';
import { getTranslationLocales } from '@/enums/locale.enum';
import { NotificationIconEnum } from '@/enums/notification_icon.enum';
import { NotificationTargetTypeEnum } from '@/enums/notification_target_type.enum';
import { NotificationTypeEnum, supportsWorkplaceTarget } from '@/enums/notification_type.enum';
import { ValidationBuilder } from '@/validator/abstract_validator';

const formInitialValue: Record<string, ParamType> = {
    date: null,
    time: '',
    type: NotificationTypeEnum.NORMAL.value,
    icon: NotificationIconEnum.ANNOUNCEMENT.value,
    title: '',
    content: '',
    url: '',
    is_manually_scheduled: false,
    target_type: NotificationTargetTypeEnum.ALL.value,
    workplace_ids: [],
    workplace_id: null,
    shift_id: null,
    gender_target: GenderTargetEnum.BOTH.value,
    requires_experience: false,
    include_nearby_workplaces: false,
    nearby_radius_km: DEFAULT_NEARBY_RADIUS_KM,
    include_frequent_urgent_group: false,
    hot_bonus_amount: null,
};

const schema: Record<string, ValidationBuilder<ParamType>> = {
    type: Validator.string().required().isSelect(),
    title: Validator.string().required(),
    content: Validator.string().required(),
    workplace_id: Validator.string()
        .isSelect()
        .requiredIf((values) => values.type === NotificationTypeEnum.RECRUITMENT.value),
    shift_id: Validator.string()
        .isSelect()
        .requiredIf((values) => values.type === NotificationTypeEnum.RECRUITMENT.value),
    gender_target: Validator.string()
        .isSelect()
        .requiredIf((values) => values.type === NotificationTypeEnum.RECRUITMENT.value),
    nearby_radius_km: Validator.number()
        .gt(0)
        .lte(MAX_NEARBY_RADIUS_KM)
        .requiredIf(
            (values) =>
                values.type === NotificationTypeEnum.RECRUITMENT.value && (values.include_nearby_workplaces as boolean),
        ),
    date: Validator.string()
        .isSelect()
        .requiredIf((values) => values.is_manually_scheduled as boolean),
    time: Validator.string().requiredIf((values) => values.is_manually_scheduled as boolean),
};

for (const locale of getTranslationLocales()) {
    formInitialValue[`translated_${locale}`] = false;
    formInitialValue[`title_${locale}`] = '';
    formInitialValue[`content_${locale}`] = '';

    schema[`title_${locale}`] = Validator.string().requiredIf((values) => values[`translated_${locale}`] as boolean);
    schema[`content_${locale}`] = Validator.string().requiredIf((values) => values[`translated_${locale}`] as boolean);
}

const isWorkplaceTarget = (values: Record<string, ParamType>) =>
    supportsWorkplaceTarget(values.type as string) && values.target_type === NotificationTargetTypeEnum.WORKPLACE.value;

const validate = {
    ...validatorResolver(schema),
    workplace_ids: (value: ParamType, values: Record<string, ParamType>) => {
        if (!isWorkplaceTarget(values)) {
            return null;
        }
        if (!Array.isArray(value) || value.length === 0) {
            return i18n.t('validation|Please select at least {{min}} items', { min: 1 });
        }
        return null;
    },
};

export { formInitialValue, isWorkplaceTarget, schema, validate };
