<?php

namespace App\ValueObjects;

use App\Enums\GenderTarget;
use App\Models\Notification;

/**
 * The targeting and display settings an admin chose for a recruitment notification.
 *
 * Stored inside the existing notifications.data JSON column rather than in columns of
 * their own: the whole set is written once and read as a unit when the notification is
 * sent, so nothing here is ever filtered, counted or joined on. The values that *are*
 * queried — the resolved recipient list and its size — live in a table and a column.
 *
 * Every accessor has a defined answer for a notification created before this feature
 * existed, whose data holds only shift_id and workplace_id. Those defaults are the
 * widest, least surprising reading: notify everyone, require nothing, advertise nothing.
 */
readonly class RecruitmentCriteria
{
    public const DEFAULT_NEARBY_RADIUS_KM = 10.0;

    private function __construct(
        public int $shiftId,
        public int $workplaceId,
        public GenderTarget $genderTarget,
        public bool $requiresExperience,
        public bool $includeNearbyWorkplaces,
        public ?float $nearbyRadiusKm,
        public bool $includeFrequentUrgentGroup,
        public ?int $hotBonusAmount
    ) {}

    public static function fromNotification(Notification $notification): self
    {
        return self::fromArray($notification->data ?? []);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $includeNearby = (bool) ($data['include_nearby_workplaces'] ?? false);
        $radius = $data['nearby_radius_km'] ?? null;

        // A radius without the toggle is meaningless, and the toggle without a usable
        // radius cannot select anything — treat either half alone as "no nearby workplaces"
        // so a legacy or half-filled payload can never widen the audience unintentionally.
        $radius = $includeNearby && is_numeric($radius) && (float) $radius > 0 ? (float) $radius : null;

        $hotBonus = $data['hot_bonus_amount'] ?? null;

        return new self(
            shiftId: (int) ($data['shift_id'] ?? 0),
            workplaceId: (int) ($data['workplace_id'] ?? 0),
            genderTarget: GenderTarget::tryFrom((string) ($data['gender_target'] ?? '')) ?? GenderTarget::Both,
            requiresExperience: (bool) ($data['requires_experience'] ?? false),
            includeNearbyWorkplaces: $radius !== null,
            nearbyRadiusKm: $radius,
            includeFrequentUrgentGroup: (bool) ($data['include_frequent_urgent_group'] ?? false),
            hotBonusAmount: is_numeric($hotBonus) ? (int) $hotBonus : null
        );
    }

    /**
     * The criteria as they are persisted into notifications.data, merged with the
     * shift and workplace keys that were already stored there before this feature.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'workplace_id' => $this->workplaceId,
            'gender_target' => $this->genderTarget->value,
            'requires_experience' => $this->requiresExperience,
            'include_nearby_workplaces' => $this->includeNearbyWorkplaces,
            'nearby_radius_km' => $this->nearbyRadiusKm,
            'include_frequent_urgent_group' => $this->includeFrequentUrgentGroup,
            'hot_bonus_amount' => $this->hotBonusAmount,
        ];
    }
}
