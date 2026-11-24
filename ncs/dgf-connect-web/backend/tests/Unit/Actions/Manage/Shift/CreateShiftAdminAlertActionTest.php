<?php

namespace Tests\Unit\Actions\Manage\Shift;

use App\Actions\Manage\Shift\CreateShiftAdminAlertAction;
use App\Enums\Locale;
use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreateShiftAdminAlertActionTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $workplace = Workplace::create([
            'name' => 'Shibuya Store',
            'address' => 'Address 1',
            'latitude' => 35.6895,
            'longitude' => 139.6917,
        ]);

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $creator = User::factory()->create();

        $this->shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creator->id,
            'date' => now()->format('Y-m-d'),
            'adjusted_date' => now()->format('Y-m-d'),
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
    }

    public function testCreatesExactlyOneNotificationPerRecipientRegardlessOfAffectedCount()
    {
        $recipients = User::factory()->count(2)->create();
        $affected = User::factory()->count(3)->create();

        $action = new CreateShiftAdminAlertAction();
        $created = $action(
            $this->shift,
            NotificationType::SHIFT_UNCONFIRMED_ALERT,
            $recipients->pluck('id')->toArray(),
            $affected->pluck('id')->toArray(),
            Carbon::parse('2026-08-10 09:00:00')
        );

        $this->assertSame(2, $created);
        $this->assertSame(
            2,
            Notification::query()->where('type', NotificationType::SHIFT_UNCONFIRMED_ALERT->value)->count()
        );
    }

    public function testInterpolatesWorkplaceNameStartTimeAndCountIntoContent()
    {
        $recipient = User::factory()->create();
        $affected = User::factory()->count(3)->create();

        $action = new CreateShiftAdminAlertAction();
        $action(
            $this->shift,
            NotificationType::SHIFT_UNCONFIRMED_ALERT,
            [$recipient->id],
            $affected->pluck('id')->toArray(),
            Carbon::parse('2026-08-10 09:00:00')
        );

        $notification = Notification::query()->where('user_id', $recipient->id)->firstOrFail();
        $this->assertStringContainsString('Shibuya Store', $notification->content);
        $this->assertStringContainsString('09:00', $notification->content);
        $this->assertStringContainsString('3', $notification->content);
    }

    public function testIncludesEveryAffectedIdentifierInDataUserIds()
    {
        $recipient = User::factory()->create();
        $affected = User::factory()->count(3)->create();

        $action = new CreateShiftAdminAlertAction();
        $action(
            $this->shift,
            NotificationType::SHIFT_MISSING_CHECK_IN_ALERT,
            [$recipient->id],
            $affected->pluck('id')->toArray(),
            Carbon::parse('2026-08-10 09:00:00')
        );

        $notification = Notification::query()->where('user_id', $recipient->id)->firstOrFail();
        $this->assertEqualsCanonicalizing($affected->pluck('id')->toArray(), $notification->data['user_ids']);
        $this->assertSame($this->shift->id, $notification->data['shift_id']);
        $this->assertSame($this->shift->workplace_id, $notification->data['workplace_id']);
    }

    public function testCreatesATranslationRowPerNonDefaultLocale()
    {
        $recipient = User::factory()->create();
        $affected = User::factory()->count(1)->create();

        $action = new CreateShiftAdminAlertAction();
        $action(
            $this->shift,
            NotificationType::SHIFT_UNCONFIRMED_ALERT,
            [$recipient->id],
            $affected->pluck('id')->toArray(),
            Carbon::parse('2026-08-10 09:00:00')
        );

        $notification = Notification::query()->where('user_id', $recipient->id)->firstOrFail();
        $this->assertGreaterThan(0, $notification->translations()->count());
        $this->assertEqualsCanonicalizing(
            Locale::getTranslationLocales(),
            $notification->translations()->pluck('lang')->toArray()
        );
    }
}
