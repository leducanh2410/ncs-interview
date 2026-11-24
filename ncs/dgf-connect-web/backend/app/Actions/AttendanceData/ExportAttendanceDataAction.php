<?php

namespace App\Actions\AttendanceData;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes the attendance data of some workplaces over a date range to a gzipped
 * JSON-lines file, so production attendance can be replayed on a dev server
 * (e.g. to let the client rehearse reconciliation against real check-ins) and the
 * imported employees can keep being used there afterwards.
 *
 * Before anything leaves the server, the data that must not reach a dev server is
 * replaced: every email is redirected to a test mailbox (a dev server sends real
 * mail), identity document numbers and the home address are faked, and secrets,
 * avatars and external-system ids are dropped. The rest of the profile is kept.
 *
 * Tables are written parents-first so ImportAttendanceDataAction can resolve
 * references in a single pass. The file ends with an `end` record carrying the
 * row counts, so a truncated copy is detected instead of half-imported.
 */
class ExportAttendanceDataAction
{
    public const FORMAT_VERSION = 1;

    /** `%d` is the production user id, which keeps the address stable across exports. */
    public const TEST_EMAIL_PATTERN = 'trungvq+dgftest%d@vistarsoft.com';

    private const CHUNK_SIZE = 1000;

    /**
     * `users` columns left out: secrets, the avatar (its file is not copied), the Cross
     * Staff id (a dev server must not sync to that system) and flags derived from
     * documents/polls that are not copied either.
     */
    private const DROPPED_USER_COLUMNS = [
        'password',
        'remember_token',
        'tfa_secret',
        'profile_picture',
        'cross_staff_id',
        'has_pending_documents',
        'has_pending_votes',
    ];

    /**
     * @param list<int> $workplaceIds
     * @return array<string, int> row count per table
     */
    public function __invoke(array $workplaceIds, Carbon $from, Carbon $to, string $path): array
    {
        $workplaceIds = array_values(array_unique(array_map('intval', $workplaceIds)));
        if ($workplaceIds === []) {
            throw new InvalidArgumentException('At least one workplace is required.');
        }
        if ($from->gt($to)) {
            throw new InvalidArgumentException('The start date must not be after the end date.');
        }

        $missing = array_diff($workplaceIds, DB::table('workplaces')->whereIn('id', $workplaceIds)->pluck('id')->all());
        if ($missing !== []) {
            throw new InvalidArgumentException('Workplace(s) not found: ' . implode(', ', $missing));
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create directory {$directory}");
        }

        // Written under a temporary name and renamed at the end, so a failed export
        // never leaves a file that looks complete at the requested path.
        $temporaryPath = $path . '.partial';
        $handle = gzopen($temporaryPath, 'wb6');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$temporaryPath} for writing");
        }

        try {
            $counts = $this->writeAll($handle, $workplaceIds, $from->toDateString(), $to->toDateString());
            gzclose($handle);
            $handle = null;

            if (!rename($temporaryPath, $path)) {
                throw new RuntimeException("Cannot move {$temporaryPath} to {$path}");
            }

            return $counts;
        } finally {
            if ($handle !== null) {
                gzclose($handle);
            }
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    /**
     * @param resource $handle
     * @param list<int> $workplaceIds
     * @return array<string, int>
     */
    private function writeAll($handle, array $workplaceIds, string $from, string $to): array
    {
        $this->writeLine($handle, [
            'type' => 'meta',
            'version' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'app_env' => app()->environment(),
            // Lets the import refuse to run on the server the file came from
            'app_url' => config('app.url'),
            'workplace_ids' => $workplaceIds,
            'from' => $from,
            'to' => $to,
        ]);

        $shiftIds = fn(Builder $query) => $query
            ->select('id')
            ->from('shifts')
            ->whereIn('workplace_id', $workplaceIds)
            // Reconciliation matches a work date against `date`, then `adjusted_date`
            // for overnight shifts, so a shift qualifies through either.
            ->where(
                fn(Builder $inner) => $inner
                    ->whereBetween('date', [$from, $to])
                    ->orWhereBetween('adjusted_date', [$from, $to])
            );
        $userIds = fn(Builder $query) => $query->select('user_id')->from('shift_users')->whereIn('shift_id', $shiftIds);

        $counts = [];
        $tables = [
            'workplaces' => DB::table('workplaces')->whereIn('id', $workplaceIds),
            'workplace_shifts' => DB::table('workplace_shifts')->whereIn('workplace_id', $workplaceIds),
            'workplace_positions' => DB::table('workplace_positions')->whereIn('workplace_id', $workplaceIds),
            'users' => DB::table('users')->whereIn('id', $userIds),
            'user_infos' => DB::table('user_infos')->whereIn('user_id', $userIds),
            'workplace_users' => DB::table('workplace_users')
                ->whereIn('workplace_id', $workplaceIds)
                ->whereIn('user_id', $userIds),
            'shifts' => DB::table('shifts')->whereIn('id', $shiftIds),
            'shift_users' => DB::table('shift_users')->whereIn('shift_id', $shiftIds),
            'check_ins' => DB::table('check_ins')->whereIn('shift_id', $shiftIds),
        ];

        foreach ($tables as $table => $query) {
            $counts[$table] = 0;
            $rows =
                $table === 'user_infos'
                    ? $query->orderBy('user_id')->lazy(self::CHUNK_SIZE)
                    : $query->lazyById(self::CHUNK_SIZE);

            foreach ($rows as $row) {
                $this->writeLine($handle, [
                    'type' => 'row',
                    'table' => $table,
                    'data' => $this->sanitize($table, (array) $row),
                ]);
                $counts[$table]++;
            }
        }

        $this->writeLine($handle, ['type' => 'end', 'counts' => $counts]);

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function sanitize(string $table, array $row): array
    {
        return match ($table) {
            'users' => ['email' => sprintf(self::TEST_EMAIL_PATTERN, $row['id'])] +
                array_diff_key($row, array_flip(self::DROPPED_USER_COLUMNS)),
            'user_infos' => $this->fakeIdentityData($row),
            default => $row,
        };
    }

    /**
     * Residence card numbers keep their 12-character shape and stay unique per user and
     * slot (the admin form validates both), so imported profiles can still be saved.
     *
     * @param array<string, mixed> $info
     * @return array<string, mixed>
     */
    private function fakeIdentityData(array $info): array
    {
        $userId = (int) $info['user_id'];
        foreach ([1 => 'AA', 2 => 'BB', 3 => 'CC'] as $slot => $suffix) {
            if ($info["residence_number_{$slot}"] !== null) {
                $info["residence_number_{$slot}"] = sprintf('TS%08d%s', $userId, $suffix);
            }
        }
        if ($info['passport_number'] !== null) {
            $info['passport_number'] = sprintf('TP%07d', $userId);
        }
        if ($info['address'] !== null) {
            $info['address'] = "テスト住所{$userId}";
        }
        if ($info['postal_code'] !== null) {
            $info['postal_code'] = '0000000';
        }

        return $info;
    }

    /**
     * @param resource $handle
     * @param array<string, mixed> $record
     */
    private function writeLine($handle, array $record): void
    {
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        if (gzwrite($handle, $line) === false) {
            throw new RuntimeException('Writing the export file failed');
        }
    }
}
