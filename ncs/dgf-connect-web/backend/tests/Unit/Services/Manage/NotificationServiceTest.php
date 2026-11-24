<?php

namespace Tests\Unit\Services\Manage;

use App\Enums\GenderTarget;
use App\Enums\Locale;
use App\Enums\NotificationType;
use App\Models\Shift;
use App\Services\Manage\NotificationService;
use App\ValueObjects\RecruitmentCriteria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRecruitmentFixtures;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use BuildsRecruitmentFixtures;
    use RefreshDatabase;

    private NotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notificationService = app(NotificationService::class);
    }

    public function testCreateOrUpdateNotificationFallsBackToOriginalTitleAndContentForLocalesWithoutTranslation()
    {
        $result = $this->notificationService->createOrUpdateNotification(null, $this->baseValues());

        $translations = $result['data']->translations()->get()->keyBy('lang');

        $expectedLocales = Locale::getBaseTranslationLocales();
        sort($expectedLocales);
        $actualLocales = $translations->keys()->sort()->values()->all();
        $this->assertSame($expectedLocales, $actualLocales);
        $this->assertSame('Base title', $translations['ne']->title);
        $this->assertSame('Base content', $translations['ne']->content);
        $this->assertSame('Base title', $translations['si']->title);
        $this->assertSame('Base content', $translations['si']->content);
        // Filler content, not a genuine translation — consumers must not treat these rows as
        // proof the recipient's language actually has translated content.
        $this->assertTrue($translations['ne']->is_fallback);
        $this->assertTrue($translations['si']->is_fallback);
        // No English translation was provided either, so the filler was copied from the base
        // title/content fields, whose language is the system's base locale.
        $this->assertSame(config('app.base_locale'), $translations['ne']->fallback_source_lang);
        $this->assertSame(config('app.base_locale'), $translations['si']->fallback_source_lang);
    }

    public function testCreateOrUpdateNotificationPrefersProvidedEnglishTranslationAsFallback()
    {
        $values = array_merge($this->baseValues(), [
            'title_en' => 'English title',
            'content_en' => 'English content',
        ]);

        $result = $this->notificationService->createOrUpdateNotification(null, $values);

        $translations = $result['data']->translations()->get()->keyBy('lang');

        $this->assertSame('English title', $translations['ne']->title);
        $this->assertSame('English content', $translations['ne']->content);
        $this->assertSame('English title', $translations['si']->title);
        $this->assertSame('English content', $translations['si']->content);
        // 'en' was explicitly provided by the admin; 'ne'/'si' only borrow its text, so only
        // the former counts as a genuine translation.
        $this->assertFalse($translations['en']->is_fallback);
        $this->assertTrue($translations['ne']->is_fallback);
        $this->assertTrue($translations['si']->is_fallback);
        $this->assertNull($translations['en']->fallback_source_lang);
        $this->assertSame('en', $translations['ne']->fallback_source_lang);
        $this->assertSame('en', $translations['si']->fallback_source_lang);
    }

    public function testCreateOrUpdateNotificationHonorsExplicitLocaleTranslation()
    {
        $values = array_merge($this->baseValues(), [
            'title_ne' => 'Nepali title',
            'content_ne' => 'Nepali content',
        ]);

        $result = $this->notificationService->createOrUpdateNotification(null, $values);

        $translations = $result['data']->translations()->get()->keyBy('lang');

        $this->assertSame('Nepali title', $translations['ne']->title);
        $this->assertSame('Nepali content', $translations['ne']->content);
        $this->assertSame('Base title', $translations['si']->title);
        $this->assertFalse($translations['ne']->is_fallback);
        $this->assertTrue($translations['si']->is_fallback);
        $this->assertNull($translations['ne']->fallback_source_lang);
        $this->assertSame(config('app.base_locale'), $translations['si']->fallback_source_lang);
    }

    public function testCreateOrUpdateNotificationStoresRecruitmentCriteriaInDataJson()
    {
        $shift = $this->createShift();

        $result = $this->notificationService->createOrUpdateNotification(
            null,
            array_merge($this->baseValues(), [
                'type' => NotificationType::RECRUITMENT->value,
                'shift_id' => $shift->id,
                'gender_target' => GenderTarget::Female->value,
                'requires_experience' => true,
                'include_nearby_workplaces' => true,
                'nearby_radius_km' => 15,
                'include_frequent_urgent_group' => true,
                'hot_bonus_amount' => 5000,
            ])
        );

        $data = $result['data']->data;

        $this->assertSame($shift->id, $data['shift_id']);
        $this->assertSame($shift->workplace_id, $data['workplace_id']);
        $this->assertSame(GenderTarget::Female->value, $data['gender_target']);
        $this->assertTrue($data['requires_experience']);
        $this->assertTrue($data['include_nearby_workplaces']);
        $this->assertTrue($data['include_frequent_urgent_group']);
        $this->assertSame(5000, $data['hot_bonus_amount']);

        // JSON does not preserve int/float distinction, so the radius contract belongs to the
        // value object — that is the path resolution actually reads through.
        $criteria = RecruitmentCriteria::fromNotification($result['data']);
        $this->assertSame(15.0, $criteria->nearbyRadiusKm);
        $this->assertSame(GenderTarget::Female, $criteria->genderTarget);

        // The criteria must live in the JSON blob only — never leak onto the model as columns.
        $this->assertArrayNotHasKey('gender_target', $result['data']->getAttributes());
        $this->assertArrayNotHasKey('hot_bonus_amount', $result['data']->getAttributes());
    }

    public function testCreateOrUpdateNotificationDiscardsCriteriaSubmittedWithNonRecruitmentType()
    {
        $result = $this->notificationService->createOrUpdateNotification(
            null,
            array_merge($this->baseValues(), [
                'gender_target' => GenderTarget::Male->value,
                'requires_experience' => true,
                'hot_bonus_amount' => 9999,
            ])
        );

        $this->assertNull($result['data']->data);
        $this->assertArrayNotHasKey('gender_target', $result['data']->getAttributes());
        $this->assertArrayNotHasKey('hot_bonus_amount', $result['data']->getAttributes());
    }

    public function testRecruitmentCriteriaFallBackToWidestDefaultsWhenAbsent()
    {
        $shift = $this->createShift();

        $result = $this->notificationService->createOrUpdateNotification(
            null,
            array_merge($this->baseValues(), [
                'type' => NotificationType::RECRUITMENT->value,
                'shift_id' => $shift->id,
            ])
        );

        $data = $result['data']->data;

        $this->assertSame(GenderTarget::Both->value, $data['gender_target']);
        $this->assertFalse($data['requires_experience']);
        $this->assertFalse($data['include_nearby_workplaces']);
        $this->assertNull($data['nearby_radius_km']);
        $this->assertFalse($data['include_frequent_urgent_group']);
        $this->assertNull($data['hot_bonus_amount']);
    }

    public function testNearbyRadiusWithoutTheToggleDoesNotWidenTheAudience()
    {
        $shift = $this->createShift();

        $result = $this->notificationService->createOrUpdateNotification(
            null,
            array_merge($this->baseValues(), [
                'type' => NotificationType::RECRUITMENT->value,
                'shift_id' => $shift->id,
                'include_nearby_workplaces' => false,
                'nearby_radius_km' => 50,
            ])
        );

        $data = $result['data']->data;

        $this->assertFalse($data['include_nearby_workplaces']);
        $this->assertNull($data['nearby_radius_km']);
    }

    private function createShift(): Shift
    {
        return $this->createShiftAt($this->createWorkplaceAt());
    }

    private function baseValues(): array
    {
        return [
            'type' => NotificationType::NORMAL->value,
            'title' => 'Base title',
            'content' => 'Base content',
            'is_manually_scheduled' => false,
        ];
    }
}
