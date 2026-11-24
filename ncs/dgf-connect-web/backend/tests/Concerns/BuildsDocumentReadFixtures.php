<?php

namespace Tests\Concerns;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\ShiftUserStatus;
use App\Enums\UserPaperStatus;
use App\Models\Document;
use App\Models\DocumentRequiredConfig;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Carbon\CarbonInterface;

trait BuildsDocumentReadFixtures
{
    protected function seedDocumentReadPermissions(): void
    {
        foreach (
            [
                PermissionName::ConfigList->value,
                PermissionName::ConfigEdit->value,
                PermissionName::DocumentList->value,
                PermissionName::WorkplaceEdit->value,
                PermissionName::UserPaperApprove->value,
                PermissionName::ManagerAuth->value,
            ]
            as $permissionName
        ) {
            Permission::firstOrCreate(['name' => $permissionName], ['display_name' => $permissionName]);
        }
    }

    protected function createGlobalAdmin(array $permissionNames = []): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => RoleName::GlobalAdmin->value], ['display_name' => 'Global Admin']);

        if (!empty($permissionNames)) {
            $role
                ->permissions()
                ->syncWithoutDetaching(Permission::query()->whereIn('name', $permissionNames)->pluck('id')->all());
        }

        $user->addRole($role);

        return $user;
    }

    protected function createWorkplaceAdmin(
        Workplace $workplace,
        array $scopedPermissionNames = [],
        array $globalPermissionNames = []
    ): User {
        $user = User::factory()->create();

        if (!empty($globalPermissionNames)) {
            $globalRole = Role::firstOrCreate(
                ['name' => 'document_read_global_list'],
                ['display_name' => 'Document Read Global List']
            );
            $globalRole
                ->permissions()
                ->syncWithoutDetaching(
                    Permission::query()->whereIn('name', $globalPermissionNames)->pluck('id')->all()
                );
            $user->addRole($globalRole);
        }

        if (!empty($scopedPermissionNames)) {
            $scopedRole = Role::firstOrCreate(
                ['name' => 'document_read_workplace_admin'],
                ['display_name' => 'Document Read Workplace Admin']
            );
            $scopedRole
                ->permissions()
                ->syncWithoutDetaching(
                    Permission::query()->whereIn('name', $scopedPermissionNames)->pluck('id')->all()
                );
            $user->addRole($scopedRole, $workplace->id);
        }

        return $user;
    }

    protected function createDocument(string $url = 'https://example.com/documents/read-confirmation.pdf'): Document
    {
        $document = Document::query()->create([
            'title' => fake()->sentence(),
            'content' => fake()->paragraph(),
        ]);

        $document->attachments()->create([
            'type' => 'link',
            'url' => $url,
            'filename' => null,
        ]);

        return $document->fresh('attachments');
    }

    protected function createWorkplace(array $attributes = []): Workplace
    {
        return Workplace::query()->create(
            array_merge(
                [
                    'name' => fake()->company(),
                    'address' => fake()->address(),
                    'latitude' => '35.6895',
                    'longitude' => '139.6917',
                ],
                $attributes
            )
        );
    }

    protected function createWorkplaceShift(Workplace $workplace): WorkplaceShift
    {
        return WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'start_break_time' => null,
            'end_break_time' => null,
            'break_duration' => null,
            'is_active' => true,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
            'date_adjustment' => 0,
        ]);
    }

    protected function createShift(Workplace $workplace, ?User $creator = null, ?string $date = null): Shift
    {
        $creator ??= User::factory()->create();
        $workplaceShift = $this->createWorkplaceShift($workplace);
        $date ??= now()->toDateString();

        return Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creator->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
            'max_registered_temp' => null,
            'break_minutes' => null,
        ]);
    }

    protected function assignApprovedShift(
        User $user,
        Workplace $workplace,
        ?CarbonInterface $approvedAt = null,
        ?User $creator = null
    ): ShiftUser {
        $shift = $this->createShift($workplace, $creator);

        return ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'created_by' => $creator?->id,
            'workplace_position_id' => null,
            'approver_id' => $creator?->id,
            'approved_at' => ($approvedAt ?? now())->toDateTimeString(),
            'rejected_note' => null,
            'status' => ShiftUserStatus::Approved->value,
            'is_fixed' => false,
            'is_overtime_allowed' => false,
            'confirmed_at' => null,
            'is_absent' => false,
        ]);
    }

    protected function createConfig(
        User $creator,
        ?Document $document = null,
        ?Workplace $workplace = null,
        array $attributes = []
    ): DocumentRequiredConfig {
        $document ??= $this->createDocument();

        return DocumentRequiredConfig::query()->create(
            array_merge(
                [
                    'document_id' => $document->id,
                    'workplace_id' => $workplace?->id,
                    'title_vi' => 'Tai lieu bat buoc',
                    'title_en' => 'Required document',
                    'title_ja' => '必須書類',
                    'is_active' => true,
                    'created_by' => $creator->id,
                ],
                $attributes
            )
        );
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create(
            array_merge(
                [
                    'paper_status' => UserPaperStatus::Registered->value,
                    'has_pending_documents' => false,
                ],
                $attributes
            )
        );
    }

    protected function attachUserRole(User $user): void
    {
        $role = Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);

        $user->addRole($role);
    }
}
