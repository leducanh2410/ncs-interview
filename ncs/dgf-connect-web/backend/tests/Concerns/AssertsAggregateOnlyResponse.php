<?php

namespace Tests\Concerns;

trait AssertsAggregateOnlyResponse
{
    private const DISALLOWED_KEYS = [
        'user_id',
        'user_name',
        'username',
        'email',
        'staff_no',
        'employee_no',
        'phone',
        'first_name',
        'last_name',
        'full_name',
    ];

    protected function assertNoUserIdentifyingFields(array $payload): void
    {
        $this->walkResponseKeys($payload, function (string $key): void {
            $this->assertNotContains(
                strtolower($key),
                self::DISALLOWED_KEYS,
                "Response leaked user-identifying key: {$key}"
            );
        });
    }

    private function walkResponseKeys(array $data, callable $visit): void
    {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $visit($key);
            }

            if (is_array($value)) {
                $this->walkResponseKeys($value, $visit);
            }
        }
    }
}
