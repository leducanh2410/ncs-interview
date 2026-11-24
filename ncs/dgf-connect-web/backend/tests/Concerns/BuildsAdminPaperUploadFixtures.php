<?php

namespace Tests\Concerns;

use App\Actions\Ocr\ValidateOcrRequiredFieldsAction;
use App\Enums\PermissionName;
use App\Enums\UserPaperType;
use App\Jobs\ExtractAdminPaperUploadOcrJob;
use App\Models\AdminPaperUpload;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Models\User;
use App\Services\External\OpenRouterOcrService;
use App\Services\Ocr\OcrImagePreparer;
use App\Support\Ocr\OcrFieldNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Shared scaffolding for the admin residence card scan tests.
 *
 * Deliberately offers no "make me a submitted upload" shortcut: every test that needs one
 * drives the real endpoints, so the states it asserts against are states the system
 * actually produces.
 */
trait BuildsAdminPaperUploadFixtures
{
    /**
     * Fields the OCR provider answers with. A test staging a card that disagrees with the
     * profile sets this before uploading.
     *
     * @var array<string, mixed>
     */
    protected array $adminScanOcrFields = [];

    protected function adminWithPermissions(PermissionName ...$permissions): User
    {
        $admin = User::factory()->create();

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName->value],
                ['display_name' => $permissionName->value]
            );
            $admin->permissions()->attach($permission);
        }

        return $admin;
    }

    protected function uploadingAdmin(): User
    {
        return $this->adminWithPermissions(PermissionName::UserPaperUpload);
    }

    /**
     * An employee the reconciliation list will actually select: role `user`, active.
     *
     * @param array<string, mixed> $info residence fields to seed on the profile
     */
    protected function employee(array $info = []): User
    {
        $user = User::factory()->create(['is_disabled' => false]);
        $user->addRole(Role::firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']));

        $user->info?->update(array_merge($this->blankResidenceSlots(), $info));

        return $user->refresh();
    }

    /**
     * UserInfoFactory fills the slots with random data, which would make every test's
     * employee accidentally comparable. Start from a profile with no card.
     *
     * @return array<string, null>
     */
    protected function blankResidenceSlots(): array
    {
        $blank = [];
        for ($slot = 1; $slot <= 3; $slot++) {
            $blank["residence_number_{$slot}"] = null;
            $blank["residence_expiry_date_{$slot}"] = null;
            $blank["residence_status_{$slot}"] = null;
        }

        return $blank;
    }

    /**
     * An employee whose profile agrees with the card the default OCR stub reads.
     *
     * The name lives on `users`, not `user_infos`, and is compared as an unordered set of
     * whitespace-separated parts, so the split between the two columns does not matter.
     *
     * @param array<string, mixed> $info residence fields to override
     */
    protected function employeeMatchingTheScan(array $info = []): User
    {
        $employee = $this->employee(array_merge($this->matchingProfile(), $info));
        $employee->update(['last_name' => 'NGUYEN VAN', 'first_name' => 'A']);

        return $employee->refresh();
    }

    /**
     * The profile of somebody whose current card is the one the default OCR stub reads.
     *
     * @return array<string, mixed>
     */
    protected function matchingProfile(): array
    {
        return [
            'birthday' => '2000-01-01',
            'gender' => 'male',
            'nationality' => 'vietnam',
            'residence_number_1' => 'AB12345678CD',
            'residence_expiry_date_1' => '2030-01-01',
            'residence_status_1' => 'study_abroad',
        ];
    }

    /**
     * Roster the employee onto a shift, so the date/workplace/shift filters have
     * something to select.
     */
    protected function rosterOnto(User $employee, string $date, string $status = 'approved'): Shift
    {
        $workplace = Workplace::factory()->create();
        $workplaceShift = WorkplaceShift::query()->create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00',
            'end_time' => '18:00',
        ]);
        $shift = Shift::query()->create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            // Every NOT NULL column on `shifts` that carries no default.
            'creator_id' => $employee->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);
        ShiftUser::query()->create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'status' => $status,
        ]);

        return $shift;
    }

    /**
     * @param array<string, mixed> $overrides fields the provider should answer with
     */
    protected function fakeAdminScanOcr(array $overrides = [], bool $unreadable = false, bool $fails = false): void
    {
        $this->adminScanOcrFields = $overrides;

        Http::fake([
            'openrouter.ai/*' => function () use ($fails, $unreadable) {
                if ($fails) {
                    return Http::response(['error' => 'upstream exploded'], 500);
                }

                $fields = $unreadable
                    ? ['name' => null, 'residence_number' => null]
                    : array_merge($this->defaultScanFields(), $this->adminScanOcrFields);

                return Http::response([
                    'model' => 'test-model',
                    'choices' => [['message' => ['content' => json_encode($fields)]]],
                ]);
            },
        ]);
    }

    /**
     * What a clean read of the card returns, before OcrFieldNormalizer normalizes it.
     *
     * @return array<string, mixed>
     */
    protected function defaultScanFields(): array
    {
        return [
            'name' => 'NGUYEN VAN A',
            'birthday' => '2000-01-01',
            'gender' => 'male',
            'nationality' => 'vietnam',
            'address' => '東京都渋谷区1-1-1',
            'prefecture' => '東京都',
            'residence_number' => 'AB12345678CD',
            'residence_expiry_date' => '2030-01-01',
            'residence_status_raw' => '留学',
        ];
    }

    protected function scanImage(): UploadedFile
    {
        return UploadedFile::fake()->image('residence-card-scan.jpg', 2400, 1600);
    }

    /**
     * Upload through the real endpoint and run the queued read, exactly as production does.
     *
     * @return array{0: \Illuminate\Testing\TestResponse, 1: AdminPaperUpload}
     */
    protected function uploadAndRead(User $admin, User $employee): array
    {
        $response = $this->actingAs($admin, 'api')->postJson("/admin/users/{$employee->id}/paper-uploads", [
            'paper_type' => UserPaperType::ForeignerCardScan->value,
            'file' => $this->scanImage(),
        ]);

        $uploadId = $response->json('data.id');
        if ($uploadId !== null) {
            $this->runOcrJob($uploadId);
        }

        return [$response, $uploadId ? AdminPaperUpload::query()->find($uploadId) : null];
    }

    protected function runOcrJob(int $uploadId): void
    {
        (new ExtractAdminPaperUploadOcrJob($uploadId))->handle(
            app(OcrImagePreparer::class),
            app(OpenRouterOcrService::class),
            app(OcrFieldNormalizer::class),
            app(ValidateOcrRequiredFieldsAction::class)
        );
    }

    /**
     * The confirm payload a client sends after reviewing what OCR read.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    protected function confirmPayload(AdminPaperUpload $upload, array $overrides = []): array
    {
        $fields = array_merge($upload->ocr_fields ?? [], $overrides);
        unset($fields['residence_status_raw']);

        return ['fields' => $fields];
    }
}
