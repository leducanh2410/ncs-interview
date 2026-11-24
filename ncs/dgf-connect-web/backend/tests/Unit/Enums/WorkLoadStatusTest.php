<?php

namespace Tests\Unit\Enums;

use App\Enums\WorkLoadStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkLoadStatusTest extends TestCase
{
    public static function hoursProvider(): array
    {
        return [
            'well below the limit' => [10.0, 28, WorkLoadStatus::Normal],
            'just below ninety percent' => [25.1, 28, WorkLoadStatus::Normal],
            'exactly ninety percent' => [25.2, 28, WorkLoadStatus::Near],
            'exactly at the limit' => [28.0, 28, WorkLoadStatus::Near],
            'floating point noise at the limit' => [28.000000001, 28, WorkLoadStatus::Near],
            'above the limit' => [28.5, 28, WorkLoadStatus::Over],
            'a few seconds above the limit' => [28.004, 28, WorkLoadStatus::Over],
            'custom higher limit' => [36.0, 40, WorkLoadStatus::Near],
        ];
    }

    #[DataProvider('hoursProvider')]
    public function testFromHoursClassifiesAgainstTheLimit(float $hours, int $maxHours, WorkLoadStatus $expected): void
    {
        $this->assertSame($expected, WorkLoadStatus::fromHours($hours, $maxHours));
    }

    public function testSeverityOrdersStatuses(): void
    {
        $this->assertLessThan(WorkLoadStatus::Near->severity(), WorkLoadStatus::Normal->severity());
        $this->assertLessThan(WorkLoadStatus::Over->severity(), WorkLoadStatus::Near->severity());
    }
}
