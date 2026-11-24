<?php

namespace App\Enums;

use App\Services\External\OpenRouterOcrService;

enum UserPaperStepKey: string
{
    use EnumTrait;

    case ResidenceCard = 'residence_card';
    case Passport = 'passport';
    case Portrait = 'portrait';
    case StudentCard = 'student_card';
    case WorkPermit = 'work_permit';
    case Other = 'other';

    /**
     * Paper item types that make up one complete upload for this step.
     *
     * @return string[] UserPaperType values
     */
    public function requiredTypes(): array
    {
        return match ($this) {
            self::ResidenceCard => [
                UserPaperType::ForeignerCardFront->value,
                UserPaperType::ForeignerCardBack->value,
                UserPaperType::ForeignerCardScan->value,
            ],
            self::Passport => [UserPaperType::Passport->value],
            self::Portrait => [UserPaperType::PortraitImage->value],
            self::StudentCard => [UserPaperType::StudentCardFront->value, UserPaperType::StudentCardBack->value],
            self::WorkPermit => [UserPaperType::WorkPermitStamp->value],
            self::Other => [UserPaperType::Other->value],
        };
    }

    /**
     * Passport pages and miscellaneous documents can span several photos;
     * every other type is exactly one image.
     */
    public function allowsMultipleFilesPerType(): bool
    {
        return $this === self::Passport || $this === self::Other;
    }

    /**
     * Step a paper item type belongs to, or null for types outside the step flow.
     */
    public static function fromPaperType(string $type): ?self
    {
        foreach (self::cases() as $step) {
            if (in_array($type, $step->requiredTypes(), true)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Primary OCR group for this step, or null when the step is not OCRed.
     * This is the run whose fields the confirm form is built from; a step may read
     * further groups for cross-checking only — see ocrGroupTypes().
     */
    public function ocrGroup(): ?string
    {
        return array_key_first($this->ocrGroupTypes()) ?: null;
    }

    /**
     * Every OCR run this step performs, as group => the paper types fed into it.
     *
     * The residence card is read twice: the physical card's sides in one run and the
     * scanned copy in another, so a scan that does not belong to the card in hand can
     * be spotted. Reading them together would average the two into one answer.
     *
     * @return array<string, string[]> ordered, primary group first
     */
    public function ocrGroupTypes(): array
    {
        return match ($this) {
            self::ResidenceCard => [
                OpenRouterOcrService::GROUP_RESIDENCE_CARD => [
                    UserPaperType::ForeignerCardFront->value,
                    UserPaperType::ForeignerCardBack->value,
                ],
                OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN => [UserPaperType::ForeignerCardScan->value],
            ],
            self::Passport => [OpenRouterOcrService::GROUP_PASSPORT => [UserPaperType::Passport->value]],
            self::StudentCard => [
                OpenRouterOcrService::GROUP_STUDENT_CARD => [
                    UserPaperType::StudentCardFront->value,
                    UserPaperType::StudentCardBack->value,
                ],
            ],
            self::Portrait, self::WorkPermit, self::Other => [],
        };
    }
}
