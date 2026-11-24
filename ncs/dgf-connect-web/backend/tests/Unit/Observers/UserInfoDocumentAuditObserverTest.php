<?php

namespace Tests\Unit\Observers;

use App\Models\User;
use App\Models\UserInfo;
use App\Observers\UserInfoDocumentAuditObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class UserInfoDocumentAuditObserverTest extends TestCase
{
    use RefreshDatabase;

    private function makeInfo(array $attributes = []): UserInfo
    {
        $user = User::factory()->create();
        $user->info->delete();

        return UserInfo::factory()->create(
            array_merge(
                [
                    'user_id' => $user->id,
                    'residence_number_1' => 'UH70860223GA',
                    'residence_expiry_date_1' => '2026-06-12',
                    'residence_status_1' => 'family_stay',
                    'passport_number' => null,
                    'passport_expiry_date' => null,
                ],
                $attributes
            )
        );
    }

    private function assertAuditLogged(callable $matcher): void
    {
        Log::shouldHaveReceived('info')
            ->withArgs(function (...$args) use ($matcher) {
                return ($args[0] ?? null) === UserInfoDocumentAuditObserver::LOG_MESSAGE && $matcher($args[1] ?? []);
            })
            ->once();
    }

    private function assertNoAuditLogged(): void
    {
        Log::shouldNotHaveReceived('info', [UserInfoDocumentAuditObserver::LOG_MESSAGE, Mockery::any()]);
    }

    public function testUpdateLogsChangedDocumentColumnsWithOldAndNewValues(): void
    {
        $info = $this->makeInfo();

        Log::spy();
        $info->update([
            'residence_expiry_date_1' => '2026-12-06',
            'passport_number' => 'TR1234567',
        ]);

        $this->assertAuditLogged(function (array $context) use ($info) {
            return $context['user_id'] === $info->user_id &&
                $context['changes'] === [
                    'residence_expiry_date_1' => ['old' => '2026-06-12', 'new' => '2026-12-06'],
                    'passport_number' => ['old' => null, 'new' => 'TR1234567'],
                ];
        });
    }

    public function testClearingADocumentColumnIsLoggedAsNull(): void
    {
        $info = $this->makeInfo();

        Log::spy();
        $info->update(['residence_expiry_date_1' => null]);

        $this->assertAuditLogged(function (array $context) {
            return $context['changes'] === [
                'residence_expiry_date_1' => ['old' => '2026-06-12', 'new' => null],
            ];
        });
    }

    public function testNonDocumentColumnChangeLogsNothing(): void
    {
        $info = $this->makeInfo();

        Log::spy();
        $info->update(['phone' => '090-0000-0000']);

        $this->assertNoAuditLogged();
    }

    public function testNullToEmptyStringIsNotLoggedAsAChange(): void
    {
        $info = $this->makeInfo(['residence_number_3' => null]);

        Log::spy();
        $info->update(['residence_number_3' => '']);

        $this->assertNoAuditLogged();
    }

    public function testCreatedLogsInitialDocumentValues(): void
    {
        $user = User::factory()->create();
        $user->info->delete();

        Log::spy();
        UserInfo::factory()->create([
            'user_id' => $user->id,
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2027-01-01',
            'residence_status_1' => 'family_stay',
            'passport_number' => null,
            'passport_expiry_date' => null,
        ]);

        $this->assertAuditLogged(function (array $context) use ($user) {
            return $context['user_id'] === $user->id &&
                ($context['changes']['residence_number_1'] ?? null) === ['old' => null, 'new' => 'AB12345678CD'] &&
                ($context['changes']['residence_expiry_date_1'] ?? null) === ['old' => null, 'new' => '2027-01-01'] &&
                !array_key_exists('passport_number', $context['changes']);
        });
    }
}
