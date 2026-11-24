<?php

namespace App\Console\Commands;

use App\Actions\User\Document\CollapseDuplicateResidenceSlotsAction;
use App\Models\UserInfo;
use App\Support\Residence\ResidenceSlotResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collapses residence slots holding the same card twice (re-confirm after rejection used to take a new slot).
 */
class FixDuplicateResidenceSlots extends Command
{
    protected $signature = 'fix:duplicate-residence-slots
        {--dry-run : Report what would change without writing}
        {--user=* : Restrict to these user ids}';

    protected $description = 'Collapse residence card slots holding the same card number more than once';

    private int $fixed = 0;

    private int $skipped = 0;

    public function handle(CollapseDuplicateResidenceSlotsAction $collapse): int
    {
        $this->fixed = 0;
        $this->skipped = 0;
        $dryRun = (bool) $this->option('dry-run');
        $userIds = array_map('intval', (array) $this->option('user'));

        $query = UserInfo::query()
            ->where(function ($q) {
                $q->whereRaw($this->sameNumberSql(1, 2))
                    ->orWhereRaw($this->sameNumberSql(2, 3))
                    ->orWhereRaw($this->sameNumberSql(1, 3));
            })
            ->orderBy('user_id');
        if ($userIds !== []) {
            $query->whereIn('user_id', $userIds);
        }

        $query->chunkById(200, function ($infos) use ($collapse, $dryRun) {
            foreach ($infos as $info) {
                $this->fixInfo($info, $collapse, $dryRun);
            }
        });

        $this->info(($dryRun ? 'Would fix ' : 'Fixed ') . "{$this->fixed} user(s).");
        if ($this->skipped > 0) {
            $this->warn("Skipped {$this->skipped} user(s) whose copies disagree; review them by hand.");
        }

        return self::SUCCESS;
    }

    private function fixInfo(UserInfo $info, CollapseDuplicateResidenceSlotsAction $collapse, bool $dryRun): void
    {
        DB::transaction(function () use ($info, $collapse, $dryRun) {
            /** @var UserInfo $info */
            $info = UserInfo::query()->whereKey($info->getKey())->lockForUpdate()->first();
            $before = ResidenceSlotResolver::cardsFromInfo($info);
            ['cards' => $after, 'unresolved' => $unresolved] = $collapse($info);

            if ($unresolved !== []) {
                $this->skipped++;
                $this->warn(
                    "user {$info->user_id}: copies of " .
                        implode(', ', $unresolved) .
                        ' differ in expiry date or status and no confirmation picks one; left untouched: ' .
                        $this->describe($before)
                );

                return;
            }

            ResidenceSlotResolver::writeCards($info, $after);
            if (!$info->isDirty()) {
                return;
            }

            $this->fixed++;
            $this->line("user {$info->user_id}: " . $this->describe($before) . ' -> ' . $this->describe($after));

            if (!$dryRun) {
                $info->save();
            }
        });
    }

    private function sameNumberSql(int $a, int $b): string
    {
        $norm = fn(int $slot) => ResidenceSlotResolver::normalizedNumberSql("residence_number_{$slot}");

        return "{$norm($a)} <> '' AND {$norm($a)} = {$norm($b)}";
    }

    /**
     * @param array<int, array{number: ?string, expiry: ?string, status: ?string}> $cards
     */
    private function describe(array $cards): string
    {
        return implode(
            ' | ',
            array_map(
                fn(array $card) => sprintf(
                    '%s/%s/%s',
                    $card['number'] ?? '-',
                    $card['expiry'] ?? '-',
                    $card['status'] ?? '-'
                ),
                $cards
            )
        );
    }
}
