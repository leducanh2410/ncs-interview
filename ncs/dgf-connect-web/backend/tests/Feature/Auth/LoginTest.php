<?php

namespace Tests\Feature\Auth;

use App\Enums\Locale;
use App\Enums\RoleName;
use App\Enums\UserPaperStatus;
use App\Models\Exam;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExamShiftFixtures;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use BuildsExamShiftFixtures;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $userRole = Role::create([
            'name' => RoleName::User->value,
            'display_name' => 'User',
            'description' => 'Regular user',
        ]);

        $this->user = User::factory()->create(['password' => bcrypt('password')]);
        $this->user->addRole($userRole);
    }

    public function testLoginAcceptsNepaliLocale(): void
    {
        $response = $this->postJson('/auth/login', $this->loginPayload(['lang' => 'ne']));

        $response->assertStatus(200);
        $this->assertSame('ne', $this->user->fresh()->settings['lang']);
    }

    public function testLoginAcceptsSinhalaLocale(): void
    {
        $response = $this->postJson('/auth/login', $this->loginPayload(['lang' => 'si']));

        $response->assertStatus(200);
        $this->assertSame('si', $this->user->fresh()->settings['lang']);
    }

    public function testLoginRejectsUnsupportedLocale(): void
    {
        $response = $this->postJson('/auth/login', $this->loginPayload(['lang' => 'fr']));

        $response->assertStatus(422);
    }

    public function testLoginDoesNotOverwriteExistingLang(): void
    {
        $this->user->update(['settings' => ['lang' => 'vi']]);

        $response = $this->postJson('/auth/login', $this->loginPayload(['lang' => 'ne']));

        $response->assertStatus(200);
        $this->assertSame('vi', $this->user->fresh()->settings['lang']);
    }

    public function testFirstLoginPendingExamsUseRequestLangNotAppDefaultLocale(): void
    {
        $this->user->update(['paper_status' => UserPaperStatus::Approved->value]);
        // An employee without a workplace is part of no exam's audience, so
        // their pending_exams would come back empty.
        $this->user->workplaces()->attach(
            Workplace::create([
                'name' => 'Tokyo',
                'address' => '1-1-1',
                'latitude' => '35.0',
                'longitude' => '139.0',
            ])->id
        );
        $this->giveExamShifts($this->user, $this->user->workplaces()->pluck('workplaces.id'));
        $exam = Exam::create([
            'is_required' => true,
            'retry_limit' => 0,
            'min_score' => 1,
            'time_limit' => 30,
            'start_at' => now()->subDay()->format('Y-m-d H:i:s'),
        ]);
        $exam->syncTranslations(array_merge(array_fill_keys(Locale::values(), 'Default title'), ['ja' => 'Shiken JA']));

        // The app's default APP_LOCALE is vi; this asserts the very first
        // login response still honors the lang the client just sent instead
        // of falling back to it.
        $response = $this->postJson('/auth/login', $this->loginPayload(['lang' => 'ja']));

        $response->assertStatus(200);
        $this->assertEquals('Shiken JA', $response->json('data.pending_exams.0.title'));
    }

    private function loginPayload(array $overrides = []): array
    {
        return array_merge(
            [
                'email' => $this->user->email,
                'password' => 'password',
                'device_id' => 'device-1',
                'device_name' => 'Test Device',
                'platform' => 'ios',
            ],
            $overrides
        );
    }
}
