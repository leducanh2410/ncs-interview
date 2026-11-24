<?php

namespace Tests\Unit;

use App\Enums\ResidenceStatus;
use App\Services\External\OpenRouterOcrService;
use App\Support\Ocr\OcrFieldNormalizer;
use PHPUnit\Framework\TestCase;

class OcrFieldNormalizerTest extends TestCase
{
    private OcrFieldNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new OcrFieldNormalizer();
    }

    public function testNormalizesResidenceCardFields(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'name' => '  Nguyen   Van A ',
            'last_name_kana' => 'グエン',
            'first_name_kana' => 'バン ア',
            'birthday' => '1998/04/12',
            'gender' => 'MALE',
            'nationality' => 'vietnam',
            'address' => ' 東京都新宿区 ',
            'residence_number' => 'ab12 3456-78cd',
            'residence_expiry_date' => '2027-01-31',
            'residence_status_raw' => '留学',
        ]);

        $this->assertSame([], $result['warnings']);
        $this->assertSame('NGUYEN VAN A', $result['fields']['name']);
        $this->assertSame('グエン', $result['fields']['last_name_kana']);
        $this->assertSame('バン ア', $result['fields']['first_name_kana']);
        $this->assertSame('1998-04-12', $result['fields']['birthday']);
        $this->assertSame('male', $result['fields']['gender']);
        $this->assertSame('vietnam', $result['fields']['nationality']);
        $this->assertSame('東京都新宿区', $result['fields']['address']);
        $this->assertSame('東京都', $result['fields']['prefecture']);
        $this->assertSame('AB12345678CD', $result['fields']['residence_number']);
        $this->assertSame('2027-01-31', $result['fields']['residence_expiry_date']);
        $this->assertSame(ResidenceStatus::StudyAbroad->value, $result['fields']['residence_status']);
        $this->assertSame('留学', $result['fields']['residence_status_raw']);
    }

    public function testMapsAllResidenceStatusLabels(): void
    {
        $expected = [
            '留学' => ResidenceStatus::StudyAbroad->value,
            '家族滞在' => ResidenceStatus::FamilyStay->value,
            '特定活動' => ResidenceStatus::DesignatedActivities->value,
            '定住者' => ResidenceStatus::LongTermResident->value,
            '永住者の配偶者等' => ResidenceStatus::SpouseOfPermanentResident->value,
            '永住者' => ResidenceStatus::PermanentResident->value,
            '日本人の配偶者等' => ResidenceStatus::SpouseOfJapaneseCitizen->value,
        ];

        foreach ($expected as $raw => $mapped) {
            $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
                'residence_status_raw' => $raw,
            ]);

            $this->assertSame($mapped, $result['fields']['residence_status']);
            $this->assertSame([], $result['warnings']);
        }
    }

    public function testUnknownResidenceStatusAddsWarningAndKeepsRawText(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'residence_status_raw' => '特定活動（本邦大学卒業者）',
        ]);

        $this->assertNull($result['fields']['residence_status']);
        $this->assertSame('特定活動（本邦大学卒業者）', $result['fields']['residence_status_raw']);
        $this->assertContains('residence_status_unmapped', $result['warnings']);
    }

    public function testUnparseableDateBecomesNullWithWarning(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_PASSPORT, [
            'passport_number' => 'C1234567',
            'passport_expiry_date' => 'not a date',
        ]);

        $this->assertNull($result['fields']['passport_expiry_date']);
        $this->assertContains('passport_expiry_date_unparseable_date', $result['warnings']);
        $this->assertSame('C1234567', $result['fields']['passport_number']);
    }

    public function testInvalidResidenceNumberFormatAddsWarningButKeepsValue(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'residence_number' => 'AB123CD',
        ]);

        $this->assertSame('AB123CD', $result['fields']['residence_number']);
        $this->assertContains('residence_number_format', $result['warnings']);
    }

    public function testInvalidEnumValuesBecomeNullWithWarnings(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'gender' => 'unknown_gender',
            'nationality' => 'atlantis',
        ]);

        $this->assertNull($result['fields']['gender']);
        $this->assertNull($result['fields']['nationality']);
        $this->assertContains('gender_invalid_value', $result['warnings']);
        $this->assertContains('nationality_invalid_value', $result['warnings']);
    }

    public function testStudentCardExpiryDateIsNormalized(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_STUDENT_CARD, [
            'student_card_expiry_date' => '2026/03/31',
        ]);

        $this->assertSame('2026-03-31', $result['fields']['student_card_expiry_date']);
        $this->assertSame([], $result['warnings']);
    }

    public function testKanaNamesAreConvertedToFullWidthKatakana(): void
    {
        // Half-width katakana with a detached dakuten, hiragana, and a full-width space
        // must all normalize to full-width katakana with single half-width spaces
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'last_name_kana' => ' ｸﾞｴﾝ ',
            'first_name_kana' => 'ばん　あ',
        ]);

        $this->assertSame('グエン', $result['fields']['last_name_kana']);
        $this->assertSame('バン ア', $result['fields']['first_name_kana']);
        $this->assertSame([], $result['warnings']);
    }

    public function testKanaNamesAreNormalizedToNfc(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'last_name_kana' => "\u{30AB}\u{3099}",
            'first_name_kana' => "\u{30CF}\u{3099}\u{30F3}",
        ]);

        $this->assertSame('ガ', $result['fields']['last_name_kana']);
        $this->assertSame('バン', $result['fields']['first_name_kana']);
        $this->assertSame([], $result['warnings']);
    }

    public function testEmptyAndMissingFieldsBecomeNull(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'name' => '',
            'last_name_kana' => '',
            'first_name_kana' => null,
            'birthday' => null,
        ]);

        $this->assertNull($result['fields']['name']);
        $this->assertNull($result['fields']['last_name_kana']);
        $this->assertNull($result['fields']['first_name_kana']);
        $this->assertNull($result['fields']['birthday']);
        $this->assertNull($result['fields']['residence_number']);
        $this->assertSame([], $result['warnings']);
    }

    public function testPrefectureUsesProviderValueBeforeAddressFallback(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'prefecture' => '大阪',
            'address' => '東京都新宿区',
        ]);

        $this->assertSame('大阪府', $result['fields']['prefecture']);
        $this->assertSame([], $result['warnings']);
    }

    public function testInvalidPrefectureBecomesNullWithWarning(): void
    {
        $result = $this->normalizer->normalize(OpenRouterOcrService::GROUP_RESIDENCE_CARD, [
            'prefecture' => '不明',
            'address' => '住所不明',
        ]);

        $this->assertNull($result['fields']['prefecture']);
        $this->assertContains('prefecture_invalid_value', $result['warnings']);
    }
}
