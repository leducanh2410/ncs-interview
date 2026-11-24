<?php

namespace Tests\Unit\Actions\Manage\Salary;

use App\Actions\Manage\Salary\GetSalariesAction;
use App\Enums\Nationality;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GetSalariesActionTest extends TestCase
{
    use RefreshDatabase;

    public function testShowsShortenedNameForSriLankaEmployee(): void
    {
        $employee = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $employee->info->update(['nationality' => Nationality::SriLanka->value]);
        Salary::factory()->create(['user_id' => $employee->id, 'month' => '2026-05']);

        $result = app(GetSalariesAction::class)(['month' => '2026-05']);

        $this->assertSame('THARINDU RAVISHANN', $result->getData()->first()->user['name']);
    }
}
