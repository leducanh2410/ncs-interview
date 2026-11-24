<?php

namespace App\Jobs;

use App\Facades\SalaryFacade;
use App\Models\Salary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class UpdateUserSalary implements ShouldQueue
{
    use Queueable;

    private readonly int $userId;

    private readonly string $month;

    private readonly bool $createIfNotExist;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, string $month, bool $createIfNotExist = false)
    {
        $this->userId = $userId;
        $this->month = $month;
        $this->createIfNotExist = $createIfNotExist;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (
            !$this->createIfNotExist &&
            !Salary::query()->where('user_id', $this->userId)->where('month', $this->month)->exists()
        ) {
            return;
        }

        $startDate = Carbon::parse($this->month)->startOfMonth();
        $endDate = Carbon::parse($this->month)->endOfMonth();

        SalaryFacade::collectUsersSalary([$this->userId], $startDate, $endDate);
    }
}
