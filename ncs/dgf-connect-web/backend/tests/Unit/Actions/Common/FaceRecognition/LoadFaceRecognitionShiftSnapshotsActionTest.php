<?php

namespace Tests\Unit\Actions\Common\FaceRecognition;

use App\Actions\Common\FaceRecognition\LoadFaceRecognitionShiftSnapshotsAction;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LoadFaceRecognitionShiftSnapshotsActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'serialized-array',
            'cache.serializable_classes' => false,
            'cache.stores.serialized-array' => [
                'driver' => 'array',
                'serialize' => true,
            ],
        ]);
        Cache::forgetDriver('serialized-array');
    }

    public function testCachesScalarShiftSnapshotsWithObjectDeserializationDisabled(): void
    {
        $now = Carbon::parse('2026-07-16 08:00:00');
        $creator = User::factory()->create();
        $workplace = Workplace::create([
            'name' => 'Snapshot workplace',
            'address' => 'Snapshot address',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '22:00:00',
            'end_time' => '06:00:00',
        ]);
        Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creator->id,
            'date' => $now->toDateString(),
            'adjusted_date' => $now->toDateString(),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $action = app(LoadFaceRecognitionShiftSnapshotsAction::class);
        $firstResult = $action->execute($now);
        $cachedValue = Cache::get(LoadFaceRecognitionShiftSnapshotsAction::CACHE_KEY);

        $this->assertSame(
            [
                [
                    'starts_at' => '2026-07-16 22:00:00',
                    'ends_at' => '2026-07-17 06:00:00',
                    'approved_users_count' => 0,
                ],
            ],
            $firstResult->all()
        );
        $this->assertSame($firstResult->all(), $cachedValue);
        $this->assertStringNotContainsString('O:', serialize($cachedValue));

        Shift::query()->delete();

        $this->assertSame($firstResult->all(), $action->execute($now)->all());
    }
}
