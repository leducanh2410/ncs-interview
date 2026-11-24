<?php

namespace App\Console\Commands;

use App\Actions\Common\Deployment\AnalyzeDeploymentWindowAction;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

class CheckDeploymentWindow extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deploy:check-window
        {--at= : Evaluate at this time (e.g. "2026-06-22 19:30"). Defaults to now}
        {--lookback=5 : Minutes to look back for recent check-in/out activity}
        {--horizons=1,2,4,12 : Look-ahead tiers in hours, tried in order until a window is found}
        {--margin=15 : Minutes around a shift boundary treated as "busy"}
        {--json : Output the raw analysis as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decide whether now is a good time to deploy: inspects nearby shift start/end times, recent check-in/out activity, and suggests the next quiet windows.';

    public function handle(AnalyzeDeploymentWindowAction $analyze): int
    {
        try {
            $at = $this->option('at') ? Carbon::parse($this->option('at')) : Carbon::now();
        } catch (InvalidFormatException $e) {
            $this->error('Could not parse --at value: ' . $this->option('at'));

            return self::INVALID;
        }

        $lookback = max(1, (int) $this->option('lookback'));
        $margin = max(1, (int) $this->option('margin'));
        $horizonSteps = $this->parseHorizons((string) $this->option('horizons'));

        $result = $analyze($at, $lookback, $horizonSteps, $margin);

        if ($this->option('json')) {
            $this->line((string) json_encode($this->jsonable($result), JSON_PRETTY_PRINT));

            return $this->exitCode($result['verdict']['level']);
        }

        $this->renderReport($result);

        return $this->exitCode($result['verdict']['level']);
    }

    private function renderReport(array $result): void
    {
        /** @var Carbon $at */
        $at = $result['at'];

        $this->newLine();
        $this->line(
            '<options=bold>Deployment window check</> @ ' . $at->format('Y-m-d H:i') . ' (' . $result['timezone'] . ')'
        );
        $this->line(str_repeat('-', 60));

        $this->renderVerdict($result['verdict']);

        $this->newLine();
        $recent = $result['recent_activity'];
        $this->line("<options=bold>Recent activity</> (last {$result['lookback_minutes']} min)");
        $this->line("  Check-ins:  {$recent['check_ins']}");
        $this->line("  Check-outs: {$recent['check_outs']}");
        $this->line("  Total:      {$recent['total']}");

        $this->newLine();
        $active = $result['active_now'];
        $this->line(
            "<options=bold>On shift now:</> {$active['workers']} worker(s) across {$active['shifts']} shift(s)"
        );

        $this->newLine();
        $this->line('<options=bold>Nearest shift boundaries</>');
        $this->table(
            ['Boundary', 'Time', 'Distance', 'Workers', 'Workplace (shift)'],
            $this->nearestRows($result['nearest'])
        );

        $this->newLine();
        $best = $result['best_windows'];
        $triedLabel = implode('h → ', $best['horizons_tried']) . 'h';

        if (empty($best['windows'])) {
            $this->line('<options=bold>Next best deploy windows</>');
            $this->warn("  No quiet window found (tried {$triedLabel}). Try a wider --horizons or relax --margin.");

            return;
        }

        $usedLabel = $best['horizon_used_hours'] . 'h';
        $this->line("<options=bold>Next best deploy windows</> (found within {$usedLabel}; tried {$triedLabel})");

        $rows = [];
        foreach ($best['windows'] as $index => $window) {
            $rows[] = [
                '#' . ($index + 1),
                $window['recommended_at']->format('H:i'),
                $window['from']->format('H:i') . '–' . $window['to']->format('H:i'),
                $window['length_minutes'] . ' min',
                '±' . $window['clearance_minutes'] . ' min',
                $window['active_workers'] . ' (' . $window['active_shifts'] . ' shifts)',
            ];
        }

        $this->table(['Rank', 'Deploy at', 'Quiet gap', 'Gap length', 'Clearance', 'Active workers'], $rows);
    }

    /**
     * Parse a comma-separated list of horizon tiers (hours) from the CLI option.
     *
     * @return array<int, int>
     */
    private function parseHorizons(string $option): array
    {
        $steps = collect(explode(',', $option))
            ->map(fn(string $part) => (int) mb_trim($part))
            ->filter(fn(int $hours) => $hours > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($steps) ? [1, 2, 4, 12] : $steps;
    }

    private function renderVerdict(array $verdict): void
    {
        $label = match ($verdict['level']) {
            'good' => '<bg=green;fg=black> GOOD TO DEPLOY </>',
            'caution' => '<bg=yellow;fg=black> CAUTION </>',
            default => '<bg=red;fg=white> AVOID </>',
        };

        $this->newLine();
        $this->line('Verdict: ' . $label);
        foreach ($verdict['reasons'] as $reason) {
            $this->line('  • ' . $reason);
        }
    }

    private function nearestRows(array $nearest): array
    {
        $labels = [
            'last_start' => 'Last shift start',
            'next_start' => 'Next shift start',
            'last_end' => 'Last shift end',
            'next_end' => 'Next shift end',
        ];

        $rows = [];
        foreach ($labels as $key => $label) {
            $boundary = $nearest[$key] ?? null;

            if ($boundary === null) {
                $rows[] = [$label, '—', '—', '—', '—'];

                continue;
            }

            $direction = str_starts_with($key, 'next') ? 'in ' : '';
            $suffix = str_starts_with($key, 'next') ? '' : ' ago';

            $rows[] = [
                $label,
                $boundary['time']->format('H:i'),
                $direction . $boundary['minutes_away'] . ' min' . $suffix,
                (string) $boundary['weight'],
                $boundary['workplace'] . ' (' . $boundary['label'] . ')',
            ];
        }

        return $rows;
    }

    private function exitCode(string $level): int
    {
        return $level === 'avoid' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Convert Carbon instances to ISO strings so the payload is JSON friendly.
     */
    private function jsonable(array $result): array
    {
        $result['at'] = $result['at']->toIso8601String();

        foreach (['last_start', 'next_start', 'last_end', 'next_end'] as $key) {
            if (!empty($result['nearest'][$key])) {
                $result['nearest'][$key]['time'] = $result['nearest'][$key]['time']->toIso8601String();
            }
        }

        $result['best_windows']['windows'] = array_map(function (array $window) {
            $window['from'] = $window['from']->toIso8601String();
            $window['to'] = $window['to']->toIso8601String();
            $window['recommended_at'] = $window['recommended_at']->toIso8601String();

            return $window;
        }, $result['best_windows']['windows']);

        return $result;
    }
}
