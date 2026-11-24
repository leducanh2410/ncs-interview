<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScanTestMembershipMismatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testReportsBothMismatchKindsAndIgnoresConsistentMemberships(): void
    {
        $testWorkplace = Workplace::factory()->create(['name' => 'Test Site', 'is_test' => true]);
        $normalWorkplace = Workplace::factory()->create(['name' => 'Real Site', 'is_test' => false]);

        $nonTestUser = User::factory()->create(['is_test' => false]);
        $testUser = User::factory()->create(['is_test' => true]);

        // Mismatch: test workplace with a non-test user.
        $testWorkplace->users()->attach($nonTestUser->id);
        // Mismatch: normal workplace with a test user.
        $normalWorkplace->users()->attach($testUser->id);
        // Consistent pairs that must NOT appear.
        $testWorkplace->users()->attach($testUser->id);
        $normalWorkplace->users()->attach($nonTestUser->id);

        $this->artisan('workplaces:scan-test-mismatches')
            ->expectsOutputToContain('Found 2 mismatched membership(s):')
            ->expectsOutputToContain('test workplace with non-test user: 1')
            ->expectsOutputToContain('normal workplace with test user:   1')
            ->expectsOutputToContain('Test Site')
            ->expectsOutputToContain('Real Site')
            ->assertExitCode(0);
    }

    public function testReportsNoMismatchesWhenDataIsConsistent(): void
    {
        $testWorkplace = Workplace::factory()->create(['is_test' => true]);
        $normalWorkplace = Workplace::factory()->create(['is_test' => false]);

        $testUser = User::factory()->create(['is_test' => true]);
        $nonTestUser = User::factory()->create(['is_test' => false]);

        $testWorkplace->users()->attach($testUser->id);
        $normalWorkplace->users()->attach($nonTestUser->id);

        $this->artisan('workplaces:scan-test-mismatches')
            ->expectsOutputToContain('No test-data membership mismatches found.')
            ->assertExitCode(0);
    }
}
