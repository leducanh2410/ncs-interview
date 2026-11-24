<?php

namespace Tests\Feature\Console;

use App\Actions\AttendanceData\ExportAttendanceDataAction;
use App\Actions\Manage\Reconciliation\ApplyReconciliationMatchAction;
use App\Enums\RoleId;
use App\Enums\RoleName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Models\Workplace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsReconciliationFixtures;
use Tests\TestCase;

class AttendanceDataTransferCommandTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReconciliationFixtures;

    private const PRODUCTION_URL = 'https://prod.example.test';

    /** @var string[] */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function testExportsOnlyTheSelectedWorkplacesAndDateRange(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $otherWorkplace = $this->makeWorkplace('Workplace B');
        $inRange = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        // Starts the day before the range but is worked on (adjusted to) its first day
        $overnight = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-24', [], '2026-08-25');
        $beforeRange = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-10');
        $this->makeEmployeeWithCheckIn($otherWorkplace, [], '2026-08-25');
        $this->makeEditRequest($inRange['checkIn'], $workplace);

        $records = $this->readRows($this->export([$workplace->id], '2026-08-25', '2026-08-31'));

        $this->assertSame([$workplace->id], $this->ids($records, 'workplaces'));
        $this->assertEqualsCanonicalizing(
            [$inRange['template']->id, $overnight['template']->id, $beforeRange['template']->id],
            $this->ids($records, 'workplace_shifts'),
            'every shift template of the workplace is exported, used in the range or not'
        );
        $this->assertSame([$inRange['position']->id], $this->ids($records, 'workplace_positions'));
        $this->assertEqualsCanonicalizing(
            [$inRange['employee']->id, $overnight['employee']->id],
            $this->ids($records, 'users'),
            'only employees with a shift in the range; shift creators and other employees stay behind'
        );
        $this->assertEqualsCanonicalizing(
            [$inRange['employee']->id, $overnight['employee']->id],
            array_column(array_column($records['user_infos'], 'data'), 'user_id')
        );
        $this->assertCount(2, $records['workplace_users']);
        $this->assertEqualsCanonicalizing(
            [$inRange['shift']->id, $overnight['shift']->id],
            $this->ids($records, 'shifts')
        );
        $this->assertEqualsCanonicalizing(
            [$inRange['shiftUser']->id, $overnight['shiftUser']->id],
            $this->ids($records, 'shift_users')
        );
        $this->assertEqualsCanonicalizing(
            [$inRange['checkIn']->id, $overnight['checkIn']->id],
            $this->ids($records, 'check_ins')
        );
        $this->assertArrayNotHasKey('work_history_edit_requests', $records);
        $this->assertArrayNotHasKey('work_history_edit_request_items', $records);
    }

    public function testExportRedirectsEmailsAndFakesIdentityNumbers(): void
    {
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            [
                'first_name' => 'Taro',
                'last_name' => 'Yamada',
                'first_name_kana' => 'タロウ',
                'last_name_kana' => 'ヤマダ',
                'staff_no' => 'S100',
                'email' => 'taro@example.com',
                'profile_picture' => 'avatars/taro.jpg',
                'tfa_secret' => 'secret',
                'cross_staff_id' => 'CS-1',
            ],
            '2026-08-25'
        );
        $employeeId = $seeded['employee']->id;
        $seeded['employee']->info()->update([
            'birthday' => '1995-06-15',
            'nationality' => 'vietnam',
            'phone' => '090-0000-0000',
            'address' => '1-2-3 Somewhere',
            'postal_code' => '1000001',
            'passport_number' => 'P1234567',
            'residence_number_1' => 'AB12345678CD',
            'residence_status_1' => 'student',
            'residence_expiry_date_1' => '2027-03-31',
            'residence_number_2' => null,
        ]);

        $records = $this->readRows($this->export([$workplace->id], '2026-08-25', '2026-08-25'));

        $user = $records['users'][0]['data'];
        $this->assertSame("trungvq+dgftest{$employeeId}@vistarsoft.com", $user['email']);
        $this->assertSame(
            array_values(
                (array) DB::table('users')
                    ->where('id', $employeeId)
                    ->first(['first_name', 'last_name', 'first_name_kana', 'last_name_kana', 'short_name', 'staff_no'])
            ),
            [
                $user['first_name'],
                $user['last_name'],
                $user['first_name_kana'],
                $user['last_name_kana'],
                $user['short_name'],
                $user['staff_no'],
            ]
        );
        foreach (['password', 'remember_token', 'tfa_secret', 'profile_picture', 'cross_staff_id'] as $column) {
            $this->assertArrayNotHasKey($column, $user);
        }

        $info = $records['user_infos'][0]['data'];
        $this->assertSame(
            [
                sprintf('TS%08dAA', $employeeId),
                null,
                sprintf('TP%07d', $employeeId),
                "テスト住所{$employeeId}",
                '0000000',
            ],
            [
                $info['residence_number_1'],
                $info['residence_number_2'],
                $info['passport_number'],
                $info['address'],
                $info['postal_code'],
            ],
            'identity numbers and the address are faked; an empty one stays empty'
        );
        $this->assertSame(
            ['1995-06-15', 'vietnam', '090-0000-0000', 'student', '2027-03-31'],
            [
                $info['birthday'],
                $info['nationality'],
                $info['phone'],
                $info['residence_status_1'],
                $info['residence_expiry_date_1'],
            ],
            'the rest of the profile is kept'
        );
    }

    public function testExportRejectsInvalidInputWithoutWritingAFile(): void
    {
        $workplace = $this->makeWorkplace();
        $path = $this->temporaryPath();

        $this->artisan('attendance-data:export', [
            '--workplace' => [$workplace->id, 999999],
            '--from' => '2026-08-01',
            '--to' => '2026-08-31',
            '--output' => $path,
        ])
            ->expectsOutputToContain('Workplace(s) not found: 999999')
            ->assertFailed();

        $this->artisan('attendance-data:export', [
            '--workplace' => [$workplace->id],
            '--from' => '2026-08-31',
            '--to' => '2026-08-01',
            '--output' => $path,
        ])
            ->expectsOutputToContain('must not be after')
            ->assertFailed();

        $this->artisan('attendance-data:export', [
            '--workplace' => [$workplace->id],
            '--from' => '2026/08/01',
            '--to' => '2026-08-31',
            '--output' => $path,
        ])
            ->expectsOutputToContain('--from must be a date')
            ->assertFailed();

        $this->artisan('attendance-data:export', [
            '--from' => '2026-08-01',
            '--to' => '2026-08-31',
            '--output' => $path,
        ])
            ->expectsOutputToContain('At least one workplace')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
        $this->assertFileDoesNotExist($path . '.partial');
    }

    public function testImportCreatesUsableEmployeesWhoseCheckInsReconciliationFinds(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $seeded = $this->makeEmployeeWithCheckIn(
            $workplace,
            ['first_name_kana' => 'タロウ', 'last_name_kana' => 'ヤマダ', 'staff_no' => 'S100'],
            '2026-08-25'
        );
        $seeded['employee']->info()->update(['residence_number_1' => 'AB12345678CD']);
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $userRole = $this->makeUserRole();
        $fallback = User::factory()->create();

        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => $fallback->id])
            // The exported employee is still in this database, so its staff number is taken
            ->expectsOutputToContain(
                "imported user #{$seeded['employee']->id} uses S100-import{$seeded['employee']->id}"
            )
            ->expectsOutputToContain('Import finished.')
            ->assertSuccessful();

        $user = User::query()
            ->where('email', "trungvq+dgftest{$seeded['employee']->id}@vistarsoft.com")
            ->sole();
        $this->assertSame(['タロウ', 'ヤマダ'], [$user->first_name_kana, $user->last_name_kana]);
        $this->assertSame("S100-import{$seeded['employee']->id}", $user->staff_no);
        $this->assertNotSame($seeded['employee']->password, $user->password, 'the password is random');
        $this->assertSame(sprintf('TS%08dAA', $seeded['employee']->id), $user->info->residence_number_1);
        $this->assertTrue(
            DB::table('role_user')
                ->where(['user_id' => $user->id, 'role_id' => $userRole->id, 'user_type' => User::class])
                ->whereNull('team_id')
                ->exists(),
            'imported employees get the User role'
        );

        $importedWorkplace = Workplace::query()->where('name', 'Workplace A')->sole();
        $checkIn = CheckIn::query()->where('user_id', $user->id)->sole();
        $this->assertSame(
            [$seeded['checkIn']->check_in_time, $seeded['checkIn']->check_out_time, 60],
            [$checkIn->check_in_time, $checkIn->check_out_time, $checkIn->break_minutes]
        );
        $this->assertSame($importedWorkplace->id, $checkIn->shift->workplace_id);
        $this->assertSame($importedWorkplace->id, $checkIn->shift->workplaceShift->workplace_id);
        $this->assertSame(60, $checkIn->shift->workplaceShift->break_duration);
        $this->assertSame($fallback->id, $checkIn->shift->creator_id, 'the shift creator was not exported');
        $this->assertSame($importedWorkplace->id, $checkIn->shiftUser->workplacePosition->workplace_id);
        $this->assertSame($importedWorkplace->id, $user->workplaces()->sole()->id);

        $found = app(ApplyReconciliationMatchAction::class)->findCheckIns($user->id, '2026-08-25', false);
        $this->assertSame([$checkIn->id], $found->pluck('id')->all());
    }

    public function testReimportOverwritesMatchedRowsAndAddsNewOnesWithoutDuplicating(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $first = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $firstExport = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        // A later, wider export overlaps the first one and brings another employee on another day
        $second = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-10');
        $secondExport = $this->export([$workplace->id], '2026-08-01', '2026-08-31');
        $this->forgetWorkplace($workplace);
        $fallback = User::factory()->create();

        $this->artisan('attendance-data:import', [
            'file' => $firstExport,
            '--fallback-user' => $fallback->id,
        ])->assertSuccessful();

        // Testing on the dev server moves the imported data on
        $user = User::query()
            ->where('email', "trungvq+dgftest{$first['employee']->id}@vistarsoft.com")
            ->sole();
        $checkIn = CheckIn::query()->where('user_id', $user->id)->sole();
        $importedWorkplace = Workplace::query()->where('name', 'Workplace A')->sole();
        $checkIn->update(['check_in_time' => '2026-08-25 19:00:00']);
        DB::table('users')
            ->where('id', $user->id)
            ->update(['first_name_kana' => 'チガウ']);
        DB::table('workplaces')
            ->where('id', $importedWorkplace->id)
            ->update(['address' => 'Changed on dev']);

        $this->artisan('attendance-data:import', ['file' => $secondExport, '--fallback-user' => $fallback->id])
            // Both employees' shift templates are configured identically
            ->expectsOutputToContain(
                "workplace_shifts #{$second['template']->id} has the same key as #{$first['template']->id}"
            )
            ->assertSuccessful();

        $this->assertSame($importedWorkplace->id, Workplace::query()->where('name', 'Workplace A')->sole()->id);
        $this->assertSame('Addr', $importedWorkplace->fresh()->address, 'overwritten with the production values');
        $this->assertSame($first['employee']->first_name_kana, $user->fresh()->first_name_kana);
        $this->assertSame($checkIn->id, CheckIn::query()->where('user_id', $user->id)->sole()->id);
        $this->assertSame($first['checkIn']->check_in_time, $checkIn->fresh()->check_in_time);
        $this->assertSame(1, DB::table('workplace_shifts')->where('workplace_id', $importedWorkplace->id)->count());

        $secondUser = User::query()
            ->where('email', "trungvq+dgftest{$second['employee']->id}@vistarsoft.com")
            ->sole();
        $secondCheckIn = CheckIn::query()->where('user_id', $secondUser->id)->sole();
        $this->assertSame('2026-08-10', $secondCheckIn->shift->date);
        $this->assertSame(
            2,
            CheckIn::query()
                ->whereIn('user_id', [$user->id, $secondUser->id])
                ->count()
        );
    }

    public function testImportOverwritesRowsThatAlreadyExistHereWithTheSameKey(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $seeded = $this->makeEmployeeWithCheckIn($workplace, ['first_name' => 'Prod'], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $email = "trungvq+dgftest{$seeded['employee']->id}@vistarsoft.com";
        $devWorkplace = $this->makeWorkplace('Workplace A');
        $devWorkplace->update(['address' => 'Dev address']);
        $devUser = User::factory()->create([
            'email' => $email,
            'first_name' => 'Dev',
            'password' => Hash::make('dev-password'),
        ]);

        $this->artisan('attendance-data:import', [
            'file' => $path,
            '--fallback-user' => $devUser->id,
        ])->assertSuccessful();

        $this->assertSame($devWorkplace->id, Workplace::query()->where('name', 'Workplace A')->sole()->id);
        $this->assertSame('Addr', $devWorkplace->fresh()->address);
        $user = User::query()->where('email', $email)->sole();
        $this->assertSame($devUser->id, $user->id);
        $this->assertSame(mb_strtoupper('Prod'), mb_strtoupper($user->first_name));
        $this->assertTrue(Hash::check('dev-password', $user->password), 'an existing password is left alone');
        $this->assertSame(
            $devWorkplace->id,
            CheckIn::query()->where('user_id', $devUser->id)->sole()->shift->workplace_id
        );
    }

    public function testImportCalculatesLateAndEarlyMinutesForFilesWithoutThem(): void
    {
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25', [
            'check_in_time' => '2026-08-25 21:20:00',
            'check_out_time' => '2026-08-26 05:30:00',
        ]);
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $lines = array_map(function (string $line) {
            $record = json_decode($line, true);
            if (($record['type'] ?? null) === 'row' && $record['table'] === 'check_ins') {
                unset($record['data']['late_minutes'], $record['data']['early_minutes']);

                return json_encode($record) . "\n";
            }

            return $line;
        }, gzfile($path));
        $this->rewrite($path, $lines);

        $this->artisan('attendance-data:import', [
            'file' => $path,
            '--fallback-user' => User::factory()->create()->id,
        ])->assertSuccessful();

        $checkIn = CheckIn::query()
            ->where('check_in_time', '2026-08-25 21:20:00')
            ->whereKeyNot($seeded['checkIn']->id)
            ->sole();
        $this->assertSame([20, 30], [$checkIn->late_minutes, $checkIn->early_minutes]);
    }

    public function testTruncatedFileImportsNothing(): void
    {
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $this->rewrite($path, array_slice(gzfile($path), 0, -1));
        $counts = $this->tableCounts();

        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => User::query()->min('id')])
            ->expectsOutputToContain('probably truncated')
            ->assertFailed();

        $this->assertSame($counts, $this->tableCounts());
    }

    public function testBrokenReferenceRollsBackRowsAlreadyImported(): void
    {
        $workplace = $this->makeWorkplace();
        $seeded = $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        // Drop the shift_users rows (and their count) so the check-in points at nothing
        $lines = array_values(
            array_filter(gzfile($path), fn(string $line) => !str_contains($line, '"table":"shift_users"'))
        );
        $end = json_decode(array_pop($lines), true);
        unset($end['counts']['shift_users']);
        $lines[] = json_encode($end) . "\n";
        $this->rewrite($path, $lines);
        $counts = $this->tableCounts();

        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => User::query()->min('id')])
            ->expectsOutputToContain("check_ins.shift_user_id references shift_users #{$seeded['shiftUser']->id}")
            ->assertFailed();

        $this->assertSame($counts, $this->tableCounts(), 'workplaces, users and shifts before the failure are gone');
    }

    public function testDryRunReportsWithoutSaving(): void
    {
        $workplace = $this->makeWorkplace();
        $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $counts = $this->tableCounts();

        $this->artisan('attendance-data:import', [
            'file' => $path,
            '--fallback-user' => User::query()->min('id'),
            '--dry-run' => true,
        ])
            ->expectsTable(
                ['Table', 'Inserted', 'Updated'],
                [
                    ['workplaces', 1, 0],
                    ['workplace_shifts', 1, 0],
                    ['workplace_positions', 1, 0],
                    ['users', 1, 0],
                    ['user_infos', 1, 0],
                    ['workplace_users', 1, 0],
                    ['shifts', 1, 0],
                    ['shift_users', 1, 0],
                    ['check_ins', 1, 0],
                ]
            )
            ->expectsOutputToContain('nothing was saved')
            ->assertSuccessful();

        $this->assertSame($counts, $this->tableCounts());
    }

    public function testFallbackUserDefaultsToTheFirstGlobalAdmin(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);

        $this->artisan('attendance-data:import', ['file' => $path])
            ->expectsOutputToContain('No global admin found')
            ->assertFailed();
        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => 999999])
            ->expectsOutputToContain('Fallback user #999999 does not exist')
            ->assertFailed();

        $role = Role::query()->forceCreate([
            'id' => RoleId::GlobalAdmin->value,
            'name' => RoleName::GlobalAdmin->value,
            'display_name' => 'Global Admin',
        ]);
        $admin = User::factory()->create();
        $admin->addRole($role);

        $this->artisan('attendance-data:import', ['file' => $path])
            ->expectsOutputToContain("credited to user #{$admin->id}")
            ->assertSuccessful();

        $importedWorkplace = Workplace::query()->where('name', 'Workplace A')->sole();
        $this->assertSame(
            $admin->id,
            (int) DB::table('shifts')->where('workplace_id', $importedWorkplace->id)->value('creator_id')
        );
    }

    public function testImportRefusesAFileExportedFromThisServerUnlessForced(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $counts = $this->tableCounts();
        // Running on the production server itself, which the dev server cannot tell apart by APP_ENV
        $this->app->detectEnvironment(fn() => 'production');
        config(['app.url' => self::PRODUCTION_URL . '/']);

        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => User::query()->min('id')])
            ->expectsOutputToContain('was exported from this server')
            ->assertFailed();
        $this->assertSame($counts, $this->tableCounts());

        $this->artisan('attendance-data:import', [
            'file' => $path,
            '--fallback-user' => User::query()->min('id'),
            '--force' => true,
        ])->assertSuccessful();
        $this->assertTrue(Workplace::query()->where('name', 'Workplace A')->exists());
    }

    public function testImportRefusesAFileThatDoesNotNameItsServerUnlessForced(): void
    {
        $workplace = $this->makeWorkplace('Workplace A');
        $this->makeEmployeeWithCheckIn($workplace, [], '2026-08-25');
        $path = $this->export([$workplace->id], '2026-08-25', '2026-08-25');
        $this->forgetWorkplace($workplace);
        $lines = gzfile($path);
        $meta = json_decode($lines[0], true);
        unset($meta['app_url']);
        $lines[0] = json_encode($meta) . "\n";
        $this->rewrite($path, $lines);
        $counts = $this->tableCounts();

        $this->artisan('attendance-data:import', ['file' => $path, '--fallback-user' => User::query()->min('id')])
            ->expectsOutputToContain('does not say which server')
            ->assertFailed();
        $this->assertSame($counts, $this->tableCounts());

        $this->artisan('attendance-data:import', [
            'file' => $path,
            '--fallback-user' => User::query()->min('id'),
            '--force' => true,
        ])->assertSuccessful();
        $this->assertTrue(Workplace::query()->where('name', 'Workplace A')->exists());
    }

    /**
     * @param list<int> $workplaceIds
     */
    private function export(array $workplaceIds, string $from, string $to): string
    {
        $path = $this->temporaryPath();
        // Exported "on production", imported afterwards "on dev"
        config(['app.url' => self::PRODUCTION_URL]);
        $this->artisan('attendance-data:export', [
            '--workplace' => $workplaceIds,
            '--from' => $from,
            '--to' => $to,
            '--output' => $path,
        ])->assertSuccessful();
        config(['app.url' => 'https://dev.example.test']);

        return $path;
    }

    /**
     * Removes an exported workplace and its attendance, so importing the export behaves as
     * on a separate dev database. The employees stay (their emails differ from the export).
     */
    private function forgetWorkplace(Workplace $workplace): void
    {
        $shiftIds = DB::table('shifts')->where('workplace_id', $workplace->id)->pluck('id');
        DB::table('check_ins')->whereIn('shift_id', $shiftIds)->delete();
        DB::table('shift_users')->whereIn('shift_id', $shiftIds)->delete();
        DB::table('shifts')->whereIn('id', $shiftIds)->delete();
        DB::table('workplace_users')->where('workplace_id', $workplace->id)->delete();
        DB::table('workplace_positions')->where('workplace_id', $workplace->id)->delete();
        DB::table('workplace_shifts')->where('workplace_id', $workplace->id)->delete();
        DB::table('workplaces')->where('id', $workplace->id)->delete();
    }

    private function makeUserRole(): Role
    {
        return Role::query()->firstOrCreate(['name' => RoleName::User->value], ['display_name' => 'User']);
    }

    private function makeEditRequest(CheckIn $checkIn, Workplace $workplace): WorkHistoryEditRequest
    {
        $request = WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $workplace->id,
            'requested_by_id' => User::factory()->create()->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        $request->items()->create([
            'field' => 'check_in_time',
            'old_value' => $checkIn->check_in_time,
            'new_value' => '2026-08-25 20:30:00',
            'reason' => 'Forgot to punch',
        ]);

        return $request;
    }

    /**
     * @return array<string, list<array<string, mixed>>> row records grouped by table
     */
    private function readRows(string $path): array
    {
        $records = array_map(fn(string $line) => json_decode($line, true), gzfile($path));
        $this->assertSame('meta', $records[0]['type']);
        $this->assertSame(ExportAttendanceDataAction::FORMAT_VERSION, $records[0]['version']);
        $this->assertSame('end', end($records)['type']);

        $grouped = [];
        foreach ($records as $record) {
            if ($record['type'] === 'row') {
                $grouped[$record['table']][] = $record;
            }
        }

        return $grouped;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $records
     * @return list<int>
     */
    private function ids(array $records, string $table): array
    {
        return array_column(array_column($records[$table] ?? [], 'data'), 'id');
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        $counts = [];
        foreach (
            ['workplaces', 'workplace_shifts', 'users', 'user_infos', 'shifts', 'shift_users', 'check_ins']
            as $table
        ) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    /**
     * @param list<string> $lines
     */
    private function rewrite(string $path, array $lines): void
    {
        $handle = gzopen($path, 'wb');
        gzwrite($handle, implode('', $lines));
        gzclose($handle);
    }

    private function temporaryPath(): string
    {
        $path = sys_get_temp_dir() . '/attendance-export-' . uniqid() . '.jsonl.gz';
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
