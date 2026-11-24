<?php

namespace Tests\Feature\Manage\Salary;

use App\Enums\Nationality;
use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalaryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalaryRequestShortNameDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function sriLankaUser(): User
    {
        $user = User::factory()->create([
            'first_name' => 'Ragalage Tharindu Ravishann',
            'last_name' => 'Karnasooriya',
        ]);
        $user->info->update(['nationality' => Nationality::SriLanka->value]);

        return $user;
    }

    public function testSalaryRequestListAndDetailReturnShortenedNameForSriLankaUsers(): void
    {
        $admin = User::factory()->create();
        $permission = Permission::firstOrCreate(
            ['name' => PermissionName::SalaryRequestList->value],
            ['display_name' => 'Salary Request List']
        );
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->addRole($role);

        $requester = $this->sriLankaUser();
        $approver = $this->sriLankaUser();

        $salaryRequest = SalaryRequest::create([
            'user_id' => $requester->id,
            'max_amount' => 2000,
            'amount' => 1000,
            'type' => SalaryRequestType::Transfer->value,
            'status' => SalaryRequestStatus::WaitForApprovalAccountant->value,
            'approver_id' => $approver->id,
            'approved_at' => now(),
        ]);

        $listResponse = $this->actingAs($admin, 'api')->getJson('/manage/salary/requests');
        $listResponse->assertStatus(200);
        $items = collect($listResponse->json('data.data'));
        $this->assertEquals('THARINDU RAVISHANN', $items->firstWhere('id', $salaryRequest->id)['user']['name']);

        $detailResponse = $this->actingAs($admin, 'api')->getJson("/manage/salary/requests/{$salaryRequest->id}");
        $detailResponse->assertStatus(200);
        $this->assertEquals('THARINDU RAVISHANN', $detailResponse->json('data.user.name'));
        $this->assertEquals('THARINDU RAVISHANN', $detailResponse->json('data.approver.name'));
    }
}
