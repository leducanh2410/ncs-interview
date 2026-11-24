<?php

namespace App\Actions\Common\Firebase;

use JsonException;

class NormalizeFirebaseMessageDataAction
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     *
     * @throws JsonException
     */
    public function execute(array $data): array
    {
        return array_map(function (mixed $value): string {
            if (is_string($value)) {
                return $value;
            }

            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }

            if (is_scalar($value) || $value === null) {
                return (string) $value;
            }

            return json_encode($value, JSON_THROW_ON_ERROR);
        }, $data);
    }
}
