<?php

namespace App\Actions\AttendanceData;

use App\Actions\Common\CheckIn\CalculateAttendanceDeviationAction;
use App\Enums\RoleId;
use App\Enums\RoleName;
use App\Models\CheckIn;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Loads a file written by ExportAttendanceDataAction into this (non-production)
 * database. Each record is matched to a local row by a natural key — a workplace
 * by name, a user by email, a shift by workplace + date + template, ... — and that
 * row is overwritten with the production values; without a match a row is
 * inserted. Importing the same or an overlapping export again therefore refreshes
 * the rows instead of duplicating them.
 *
 * References to users that are not part of the export (the admins who created a
 * shift or approved a registration) point at a local fallback user. Imported
 * employees get the User role so they can keep being used on this server.
 *
 * The whole file is applied in one transaction: a truncated file or a broken
 * reference leaves the database exactly as it was.
 *
 * Importing into the server the file was exported from (i.e. production) is refused
 * unless forced. That is decided by APP_URL, not APP_ENV: the dev server runs with
 * APP_ENV=production too.
 */
class ImportAttendanceDataAction
{
    private const REQUIRED = 'required';

    private const NULLABLE = 'nullable';

    private const FALLBACK_USER = 'fallback_user';

    /**
     * The tables a file may contain, in the order they must appear:
     * - `key`: columns (after references are resolved) identifying the local row to overwrite
     * - `refs`: how each foreign key column is resolved — REQUIRED fails the import when the
     *   parent is not in the file, NULLABLE clears it and FALLBACK_USER uses the fallback user
     *
     * A shift template has no unique column, so every setting that shapes attendance is part
     * of its key: two templates differing only in break or rounding must not be merged.
     *
     * @var array<string, array{key: list<string>, refs: array<string, array{0: string, 1: string}>}>
     */
    private const TABLES = [
        'workplaces' => ['key' => ['name'], 'refs' => []],
        'workplace_shifts' => [
            'key' => [
                'workplace_id',
                'start_time',
                'end_time',
                'start_break_time',
                'end_break_time',
                'break_duration',
                'check_in_round_type',
                'check_in_round_minutes',
                'check_out_round_type',
                'check_out_round_minutes',
                'date_adjustment',
            ],
            'refs' => ['workplace_id' => ['workplaces', self::REQUIRED]],
        ],
        'workplace_positions' => [
            'key' => ['workplace_id', 'name'],
            'refs' => ['workplace_id' => ['workplaces', self::REQUIRED]],
        ],
        'users' => ['key' => ['email'], 'refs' => []],
        'user_infos' => ['key' => ['user_id'], 'refs' => ['user_id' => ['users', self::REQUIRED]]],
        'workplace_users' => [
            'key' => ['workplace_id', 'user_id'],
            'refs' => [
                'workplace_id' => ['workplaces', self::REQUIRED],
                'user_id' => ['users', self::REQUIRED],
            ],
        ],
        'shifts' => [
            'key' => ['workplace_id', 'date', 'workplace_shift_id'],
            'refs' => [
                'workplace_id' => ['workplaces', self::REQUIRED],
                'workplace_shift_id' => ['workplace_shifts', self::REQUIRED],
                'creator_id' => ['users', self::FALLBACK_USER],
            ],
        ],
        'shift_users' => [
            'key' => ['shift_id', 'user_id'],
            'refs' => [
                'shift_id' => ['shifts', self::REQUIRED],
                'user_id' => ['users', self::REQUIRED],
                'created_by' => ['users', self::FALLBACK_USER],
                'workplace_position_id' => ['workplace_positions', self::NULLABLE],
                'approver_id' => ['users', self::FALLBACK_USER],
            ],
        ],
        'check_ins' => [
            'key' => ['shift_user_id'],
            'refs' => [
                'shift_user_id' => ['shift_users', self::REQUIRED],
                'shift_id' => ['shifts', self::REQUIRED],
                'user_id' => ['users', self::REQUIRED],
                'checked_in_by' => ['users', self::FALLBACK_USER],
                'checked_out_by' => ['users', self::FALLBACK_USER],
            ],
        ],
    ];

    /** @var array<string, array<int, int>> table => production id => local id */
    private array $idMap = [];

    /** @var array<string, array<string, int>> table => key => production id of the first row with it */
    private array $seenKeys = [];

    /** @var array<string, list<string>> */
    private array $columns = [];

    private int $fallbackUserId;

    private ?int $userRoleId = null;

    private ?string $randomPassword = null;

    public function __construct(private readonly CalculateAttendanceDeviationAction $calculateDeviation) {}

    /**
     * @return array{
     *     meta: array<string, mixed>,
     *     tables: array<string, array{inserted: int, updated: int}>,
     *     warnings: list<string>,
     *     fallback_user_id: int
     * }
     */
    public function __invoke(
        string $path,
        ?int $fallbackUserId = null,
        bool $dryRun = false,
        bool $force = false
    ): array {
        $this->idMap = [];
        $this->seenKeys = [];
        $this->columns = [];
        $this->randomPassword = null;
        $this->fallbackUserId = $this->resolveFallbackUserId($fallbackUserId);
        $userRoleId = DB::table('roles')->where('name', RoleName::User->value)->value('id');
        $this->userRoleId = $userRoleId !== null ? (int) $userRoleId : null;

        $handle = is_file($path) ? gzopen($path, 'rb') : false;
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        DB::beginTransaction();
        try {
            $result = $this->importLines($handle, $force);
            $this->recalculateAttendanceDeviation();

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        } finally {
            gzclose($handle);
        }

        if ($this->userRoleId === null) {
            $result['warnings'][] = 'The User role does not exist here, so imported users got no role.';
        }

        return $result + ['fallback_user_id' => $this->fallbackUserId];
    }

    /**
     * @param resource $handle
     * @return array{meta: array<string, mixed>, tables: array<string, array{inserted: int, updated: int}>, warnings: list<string>}
     */
    private function importLines($handle, bool $force): array
    {
        $meta = null;
        $end = null;
        $stats = [];
        $warnings = [];

        while (($line = gzgets($handle)) !== false) {
            if (mb_trim($line) === '') {
                continue;
            }
            if ($end !== null) {
                throw new RuntimeException('The file has data after its end record.');
            }

            $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);

            if ($meta === null) {
                if (($record['type'] ?? null) !== 'meta') {
                    throw new RuntimeException('The file does not start with an attendance export header.');
                }
                if (($record['version'] ?? null) !== ExportAttendanceDataAction::FORMAT_VERSION) {
                    throw new RuntimeException('Unsupported export format version: ' . ($record['version'] ?? 'none'));
                }
                if (!$force) {
                    $this->assertExportedElsewhere($record);
                }
                $meta = $record;

                continue;
            }

            if ($record['type'] === 'end') {
                $end = $record;

                continue;
            }

            $table = $record['table'] ?? null;
            if ($record['type'] !== 'row' || !array_key_exists($table, self::TABLES)) {
                throw new RuntimeException('Unexpected record in the file: ' . Str::limit($line, 200));
            }

            $stats[$table] ??= ['inserted' => 0, 'updated' => 0];
            $inserted = $this->importRow($table, $record['data'], $warnings);
            $stats[$table][$inserted ? 'inserted' : 'updated']++;
        }

        if ($meta === null) {
            throw new RuntimeException('The file is empty.');
        }
        if ($end === null) {
            throw new RuntimeException('The file has no end record; it is probably truncated.');
        }
        foreach ($end['counts'] as $table => $count) {
            $imported = ($stats[$table]['inserted'] ?? 0) + ($stats[$table]['updated'] ?? 0);
            if ($imported !== $count) {
                throw new RuntimeException("Expected {$count} {$table} rows but the file contains {$imported}.");
            }
        }

        return ['meta' => $meta, 'tables' => $stats, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function assertExportedElsewhere(array $meta): void
    {
        $sourceUrl = $meta['app_url'] ?? null;
        if ($sourceUrl === null) {
            throw new RuntimeException(
                'The file does not say which server it was exported from; re-export it, or pass --force if this is not the server it came from.'
            );
        }
        if (mb_rtrim((string) $sourceUrl, '/') === mb_rtrim((string) config('app.url'), '/')) {
            throw new RuntimeException(
                "The file was exported from this server ({$sourceUrl}); importing it here would add test users to that data. Pass --force if that is really intended."
            );
        }
    }

    private function recalculateAttendanceDeviation(): void
    {
        $checkInIds = array_values($this->idMap['check_ins'] ?? []);
        if ($checkInIds === []) {
            return;
        }

        CheckIn::query()
            ->whereKey($checkInIds)
            ->with('shift.workplaceShift')
            ->chunkById(500, function (Collection $checkIns) {
                foreach ($checkIns as $checkIn) {
                    if (!$checkIn->shift?->workplaceShift) {
                        continue;
                    }
                    CheckIn::query()->whereKey($checkIn->id)->update(($this->calculateDeviation)($checkIn));
                }
            });
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $warnings
     * @return bool whether a new local row was inserted (false: an existing one was overwritten)
     */
    private function importRow(string $table, array $row, array &$warnings): bool
    {
        $definition = self::TABLES[$table];
        $data = array_intersect_key($row, array_flip($this->columnsOf($table)));
        unset($data['id']);

        foreach ($definition['refs'] as $column => [$parent, $policy]) {
            if (isset($data[$column])) {
                $data[$column] = $this->resolveReference($table, $column, $parent, $policy, (int) $data[$column]);
            }
        }

        $key = [];
        foreach ($definition['key'] as $column) {
            if (!array_key_exists($column, $data)) {
                throw new RuntimeException("{$table} row #" . ($row['id'] ?? '?') . " has no {$column}.");
            }
            $key[$column] = $data[$column];
        }
        $this->warnOnSharedKey($table, $key, $row, $warnings);

        // user_infos has no id of its own; it is one row per user
        if (!isset($row['id'])) {
            $exists = $this->keyQuery($table, $key)->exists();
            $exists ? $this->keyQuery($table, $key)->update($data) : DB::table($table)->insert($data);

            return !$exists;
        }

        $targetId = $this->keyQuery($table, $key)
            // Prefer a row in the same active state, so an active and a retired position
            // sharing a name do not collapse into one
            ->when(
                array_key_exists('is_active', $data),
                fn(Builder $query) => $query->orderByRaw('is_active = ? desc', [(int) $data['is_active']])
            )
            ->orderBy('id')
            ->value('id');

        if ($table === 'users') {
            $data = $this->prepareUser($data, (int) $row['id'], $targetId, $warnings);
        }

        $inserted = $targetId === null;
        if ($inserted) {
            $targetId = DB::table($table)->insertGetId($data);
        } else {
            DB::table($table)->where('id', $targetId)->update($data);
        }
        $this->idMap[$table][(int) $row['id']] = (int) $targetId;

        if ($table === 'users') {
            $this->ensureUserRole((int) $targetId);
        }

        return $inserted;
    }

    private function resolveReference(
        string $table,
        string $column,
        string $parent,
        string $policy,
        int $sourceId
    ): ?int {
        $targetId = $this->idMap[$parent][$sourceId] ?? null;
        if ($targetId !== null) {
            return $targetId;
        }

        return match ($policy) {
            self::FALLBACK_USER => $this->fallbackUserId,
            self::NULLABLE => null,
            default => throw new RuntimeException(
                "{$table}.{$column} references {$parent} #{$sourceId}, which is not in the file."
            ),
        };
    }

    /**
     * @param array<string, mixed> $key
     */
    private function keyQuery(string $table, array $key): Builder
    {
        $query = DB::table($table);
        foreach ($key as $column => $value) {
            $value === null ? $query->whereNull($column) : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * Two production rows with the same key end up as one local row (e.g. two shift
     * templates configured identically), which is worth knowing about.
     *
     * @param array<string, mixed> $key
     * @param array<string, mixed> $row
     * @param list<string> $warnings
     */
    private function warnOnSharedKey(string $table, array $key, array $row, array &$warnings): void
    {
        $hash = json_encode($key, JSON_UNESCAPED_UNICODE);
        $sourceId = (int) ($row['id'] ?? ($row['user_id'] ?? 0));
        $firstSourceId = $this->seenKeys[$table][$hash] ?? null;

        if ($firstSourceId === null) {
            $this->seenKeys[$table][$hash] = $sourceId;

            return;
        }

        $warnings[] = "{$table} #{$sourceId} has the same key as #{$firstSourceId} ({$hash}); both were merged into one row.";
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $warnings
     * @return array<string, mixed>
     */
    private function prepareUser(array $data, int $sourceId, ?int $targetId, array &$warnings): array
    {
        if ($targetId === null) {
            // Not the production password; an admin resets it when someone needs to log in
            $data['password'] = $this->randomPassword ??= Hash::make(Str::random(40));
        }

        if (isset($data['staff_no'])) {
            $taken = DB::table('users')
                ->where('staff_no', $data['staff_no'])
                ->when($targetId !== null, fn(Builder $query) => $query->where('id', '!=', $targetId))
                ->exists();
            if ($taken) {
                $renamed = "{$data['staff_no']}-import{$sourceId}";
                $warnings[] = "Staff number {$data['staff_no']} already belongs to another user; imported user #{$sourceId} uses {$renamed}.";
                $data['staff_no'] = $renamed;
            }
        }

        return $data;
    }

    private function ensureUserRole(int $userId): void
    {
        if ($this->userRoleId === null) {
            return;
        }

        $attributes = [
            'role_id' => $this->userRoleId,
            'user_id' => $userId,
            'user_type' => User::class,
        ];
        // The unique index includes the nullable team_id, so it does not stop duplicates here
        if (!DB::table('role_user')->where($attributes)->whereNull('team_id')->exists()) {
            DB::table('role_user')->insert($attributes + ['team_id' => null]);
        }
    }

    /**
     * Writable columns of the local table: columns the file has but this schema lacks are
     * ignored, and generated columns are left to the database.
     *
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return $this->columns[$table] ??= collect(Schema::getColumns($table))
            ->filter(fn(array $column) => empty($column['generation']))
            ->pluck('name')
            ->all();
    }

    private function resolveFallbackUserId(?int $fallbackUserId): int
    {
        if ($fallbackUserId !== null) {
            if (!DB::table('users')->where('id', $fallbackUserId)->exists()) {
                throw new RuntimeException("Fallback user #{$fallbackUserId} does not exist.");
            }

            return $fallbackUserId;
        }

        $globalAdminId = DB::table('role_user')
            ->where('role_id', RoleId::GlobalAdmin->value)
            ->join('users', 'users.id', '=', 'role_user.user_id')
            ->orderBy('users.id')
            ->value('users.id');
        if ($globalAdminId === null) {
            throw new RuntimeException('No global admin found to use as the fallback user; pass one explicitly.');
        }

        return (int) $globalAdminId;
    }
}
