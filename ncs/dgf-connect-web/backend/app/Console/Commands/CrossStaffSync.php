<?php

namespace App\Console\Commands;

use App\Actions\Admin\User\SyncFromCrossStaffAction;
use App\Actions\Admin\User\SyncToCrossStaffAction;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class CrossStaffSync extends Command
{
    protected $signature = 'crossstaff:sync
        {user : User id, email, or staff_no}
        {--direction=to : Sync direction: to|from}';

    protected $description = 'Debug CrossStaff sync (to/from). Logs request, response, converted data, and errors. WARNING: performs real API/DB writes.';

    public function handle(SyncToCrossStaffAction $syncTo, SyncFromCrossStaffAction $syncFrom): int
    {
        $identifier = (string) $this->argument('user');
        $direction = (string) $this->option('direction');

        if (!in_array($direction, ['to', 'from'], true)) {
            $this->error("Invalid --direction: {$direction}. Use 'to' or 'from'.");

            return self::INVALID;
        }

        $user = $this->resolveUser($identifier);
        if (!$user) {
            $this->error("User not found by id/email/staff_no: {$identifier}");

            return self::FAILURE;
        }

        $this->printUser($user);
        $this->printConfig();

        $debug = function (string $step, array $context): void {
            $this->line('');
            $this->line("<fg=cyan>── {$step} ──</>");
            foreach ($context as $key => $value) {
                $this->line("<fg=yellow>{$key}:</>");
                $this->line($this->stringify($value));
            }
        };

        $this->line('');
        $this->info("Running sync direction: {$direction}");

        try {
            $result = $direction === 'to' ? $syncTo($user, $debug) : $syncFrom($user, $debug);
        } catch (Throwable $e) {
            $this->line('');
            $this->error('Exception thrown during sync:');
            $this->line((string) $e);

            return self::FAILURE;
        }

        $this->line('');
        $this->line('<fg=cyan>── Result ──</>');
        $error = $result->getError();
        if ($error) {
            $this->error('Error code: ' . ($error->value ?? $error->name));
            $this->line('Status: ' . $result->getStatus());
            $this->line('Data: ' . $this->stringify($result->getData()));

            return self::FAILURE;
        }

        $this->info('Sync succeeded.');
        $this->line('Data: ' . $this->stringify($result->getData()));

        return self::SUCCESS;
    }

    private function resolveUser(string $identifier): ?User
    {
        $query = User::query()->with('info');

        if (ctype_digit($identifier)) {
            $user = (clone $query)->whereKey($identifier)->first();
            if ($user) {
                return $user;
            }
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = (clone $query)->where('email', $identifier)->first();
            if ($user) {
                return $user;
            }
        }

        return (clone $query)->where('staff_no', $identifier)->first();
    }

    private function printUser(User $user): void
    {
        $this->line('<fg=cyan>── Local user ──</>');
        $info = $user->info;
        $rows = [
            ['id', $user->id],
            ['email', $user->email],
            ['staff_no', $user->staff_no],
            ['cross_staff_id', $user->cross_staff_id ?? null],
            ['last_name', $user->last_name],
            ['first_name', $user->first_name],
            ['last_name_kana', $user->last_name_kana],
            ['first_name_kana', $user->first_name_kana],
            ['info.birthday', $info?->birthday],
            ['info.gender', $info?->gender?->value ?? $info?->gender],
            ['info.nationality', $info?->nationality?->value ?? $info?->nationality],
            ['info.phone', $info?->phone],
            ['info.postal_code', $info?->postal_code],
            ['info.prefecture', $info?->prefecture],
            ['info.address', $info?->address],
            ['info.residence_number_1', $info?->residence_number_1],
            ['info.residence_expiry_date_1', $info?->residence_expiry_date_1],
            ['info.residence_status_1', $info?->residence_status_1?->value ?? $info?->residence_status_1],
            ['info.passport_number', $info?->passport_number],
            ['info.passport_expiry_date', $info?->passport_expiry_date],
        ];
        $this->table(['field', 'value'], array_map(fn($r) => [$r[0], $this->scalar($r[1])], $rows));
    }

    private function printConfig(): void
    {
        $this->line('<fg=cyan>── Config ──</>');
        $apiKey = config('services.cross_staff.api_key');
        $rows = [
            ['base_url', config('services.cross_staff.base_url')],
            ['tenant_cd', config('services.cross_staff.tenant_cd')],
            ['external_cd', config('services.cross_staff.external_cd')],
            ['api_key', $apiKey ? '*** (len=' . mb_strlen($apiKey) . ')' : '<empty>'],
        ];
        $this->table(['key', 'value'], array_map(fn($r) => [$r[0], $this->scalar($r[1])], $rows));
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '<null>';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?:
            var_export($value, true);
    }
}
