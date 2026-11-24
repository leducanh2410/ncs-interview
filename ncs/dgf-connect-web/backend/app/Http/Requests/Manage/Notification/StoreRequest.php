<?php

namespace App\Http\Requests\Manage\Notification;

use App\Enums\GenderTarget;
use App\Enums\Locale;
use App\Enums\NotificationTargetType;
use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends FormRequest
{
    /** Upper bound for the nearby-workplace radius, in kilometres. */
    public const MAX_NEARBY_RADIUS_KM = 200;

    public function rules(): array
    {
        $rules = [
            'date' => ['bail', 'nullable', 'date_format:Y-m-d'],
            'time' => ['bail', 'nullable', 'date_format:H:i'],
            'type' => ['bail', 'required', Rule::in(NotificationType::adminCreatableValues())],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'content' => ['bail', 'required', 'string', 'max:255'],
            'icon' => ['bail', 'nullable', 'string'],
            'url' => ['bail', 'nullable', 'url'],
            'is_manually_scheduled' => ['bail', 'required', 'boolean'],
        ];

        $type = NotificationType::tryFrom((string) $this->input('type'));
        if ($type?->supportsWorkplaceTarget()) {
            $rules['target_type'] = ['bail', 'nullable', Rule::in(NotificationTargetType::values())];
            $isWorkplaceTarget = $this->input('target_type') === NotificationTargetType::Workplace->value;
            $rules['workplace_ids'] = $isWorkplaceTarget
                ? ['bail', 'required', 'array', 'min:1']
                : ['bail', 'nullable', 'array'];
            $rules['workplace_ids.*'] = ['bail', 'integer', 'distinct', 'exists:workplaces,id'];
        }

        if ($type === NotificationType::BANK_ACCOUNT_REQUIRED) {
            unset($rules['url']);
        }

        if ($this->input('type') === NotificationType::RECRUITMENT->value) {
            $rules['shift_id'] = ['bail', 'required', 'exists:shifts,id'];
            unset($rules['icon'], $rules['url']);

            // Targeting criteria. Kept inside this branch so they can only ever be attached
            // to a recruitment notification; anything submitted with another type is dropped
            // by the service rather than rejected, mirroring how icon/url are handled here.
            $rules['gender_target'] = ['bail', 'required', Rule::in(GenderTarget::values())];
            $rules['requires_experience'] = ['bail', 'required', 'boolean'];
            $rules['include_nearby_workplaces'] = ['bail', 'required', 'boolean'];
            $rules['include_frequent_urgent_group'] = ['bail', 'required', 'boolean'];
            $rules['hot_bonus_amount'] = ['bail', 'nullable', 'integer', 'min:0'];

            // Bounded above as well as below: the radius pulls in every workplace within
            // reach, so an unbounded value would quietly turn a targeted notification into
            // a nationwide one.
            $rules['nearby_radius_km'] = $this->boolean('include_nearby_workplaces')
                ? ['bail', 'required', 'numeric', 'gt:0', 'max:' . self::MAX_NEARBY_RADIUS_KM]
                : ['bail', 'nullable', 'numeric'];
        }

        if ($this->input('is_manually_scheduled')) {
            $rules['date'] = ['bail', 'required', 'date_format:Y-m-d'];
            $rules['time'] = ['bail', 'required', 'date_format:H:i'];
        }

        foreach (Locale::getBaseTranslationLocales() as $locale) {
            $rules["title_{$locale}"] = ['bail', 'nullable', "required_with:content_{$locale}", 'string', 'max:255'];
            $rules["content_{$locale}"] = ['bail', 'nullable', "required_with:title_{$locale}", 'string', 'max:255'];
        }

        return $rules;
    }
}
