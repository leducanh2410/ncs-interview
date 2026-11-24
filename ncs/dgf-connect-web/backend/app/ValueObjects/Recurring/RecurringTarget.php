<?php

namespace App\ValueObjects\Recurring;

use App\Enums\RecurringCompletableType;

readonly class RecurringTarget
{
    /**
     * @param list<int> $workplaceIds
     */
    public function __construct(public RecurringCompletableType $type, public int $id, public array $workplaceIds) {}

    public function key(int $workplaceId): string
    {
        return self::keyFor($this->type, $this->id, $workplaceId);
    }

    public static function keyFor(RecurringCompletableType $type, int $id, int $workplaceId): string
    {
        return $type->value . ':' . $id . ':' . $workplaceId;
    }
}
