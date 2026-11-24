<?php

namespace App\Enums;

enum GenderTarget: string
{
    use EnumTrait;

    case Male = 'male';
    case Female = 'female';
    case Both = 'both';

    /**
     * The recorded genders a candidate may have to satisfy this target.
     *
     * Returns null when the target imposes no restriction. Candidates with no recorded
     * gender are never excluded by the caller: profile data is frequently incomplete, and
     * losing a willing worker costs more than notifying someone outside the intent.
     *
     * @return array<int, string>|null
     */
    public function matchingGenders(): ?array
    {
        return match ($this) {
            self::Male => [Gender::Male->value],
            self::Female => [Gender::Female->value],
            self::Both => null,
        };
    }
}
