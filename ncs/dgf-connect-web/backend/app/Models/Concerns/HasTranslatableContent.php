<?php

namespace App\Models\Concerns;

use App\Enums\Locale;
use App\Models\ExamTranslation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Backs one or more translatable fields (one row per language per field)
 * stored in `exam_translations`, shared by Exam/Question/QuestionOption.
 * Adding a new supported language only means adding it to the `Locale` enum,
 * no schema change needed. Adding a new translatable field to a model only
 * means passing an explicit `$field` to these methods - `translatableField()`
 * is just the default used by every call site that doesn't.
 */
trait HasTranslatableContent
{
    /**
     * `exam_translations` has no FK constraint on translatable_id (polymorphic),
     * so orphaned rows must be cleaned up explicitly on delete.
     */
    public static function bootHasTranslatableContent(): void
    {
        static::deleting(function ($model) {
            $model->translations()->delete();
        });
    }

    /**
     * The field resolved when a caller doesn't pass one explicitly, e.g.
     * `'title'` for Exam, `'content'` for Question/QuestionOption.
     */
    abstract public function translatableField(): string;

    public function translations(): MorphMany
    {
        return $this->morphMany(ExamTranslation::class, 'translatable');
    }

    /**
     * Resolve a single value for the given locale, falling back to the app's
     * configured fallback locale (`APP_FALLBACK_LOCALE`), then to Vietnamese.
     * Mirrors the frontend's `TranslatableHelper.resolve()` fallback chain
     * (locale -> en -> vi) so a translation missing/mistyped on one side
     * doesn't display differently between the API and the admin page.
     */
    public function translation(string $locale, ?string $field = null): ?string
    {
        $translations = $this->loadedTranslations($field ?? $this->translatableField());
        $fallbackLocale = config('app.fallback_locale', 'en');

        return $translations->firstWhere('lang', $locale)?->value ??
            ($translations->firstWhere('lang', $fallbackLocale)?->value ??
                $translations->firstWhere('lang', 'vi')?->value);
    }

    /**
     * Return every supported language side by side, used by admin-facing
     * responses so the edit form can populate all language tabs at once.
     */
    public function translationsMap(?string $field = null): array
    {
        $translations = $this->loadedTranslations($field ?? $this->translatableField());

        return collect(Locale::values())
            ->mapWithKeys(fn(string $lang) => [$lang => $translations->firstWhere('lang', $lang)?->value])
            ->all();
    }

    /**
     * Upsert one row per language present in $values, e.g. ['vi' => '...', 'en' => '...'].
     */
    public function syncTranslations(array $values, ?string $field = null): void
    {
        $field ??= $this->translatableField();
        foreach (Locale::values() as $lang) {
            if (!array_key_exists($lang, $values)) {
                continue;
            }
            $this->translations()->updateOrCreate(['lang' => $lang, 'field' => $field], ['value' => $values[$lang]]);
        }
    }

    /**
     * Upsert every language in $values that's also in $allowedLangs, and
     * delete any existing row for this field whose language fell out of
     * $allowedLangs. Without the prune step, unchecking a language on an
     * already-saved exam would leave its old translation reachable: a user
     * on that locale would still see the stale text since translation()
     * matches the exact locale before ever falling back to English.
     */
    public function replaceTranslations(array $values, array $allowedLangs, ?string $field = null): void
    {
        $field ??= $this->translatableField();
        $this->syncTranslations(array_intersect_key($values, array_flip($allowedLangs)), $field);
        $this->translations()->where('field', $field)->whereNotIn('lang', $allowedLangs)->delete();
    }

    private function loadedTranslations(string $field): Collection
    {
        $translations = $this->relationLoaded('translations') ? $this->translations : $this->translations()->get();

        return $translations->where('field', $field)->values();
    }
}
