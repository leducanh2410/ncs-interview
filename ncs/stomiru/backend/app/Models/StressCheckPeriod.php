<?php

namespace App\Models;

use App\Enums\StressCheckPeriodStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StressCheckPeriod extends AbstractModel
{
    protected $guarded = [];

    public array $sortable = ['id', 'year', 'title', 'start_date', 'end_date', 'status'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function question_set(): BelongsTo
    {
        return $this->belongsTo(QuestionSet::class);
    }

    public function examSessions(): HasMany
    {
        return $this->hasMany(ExamSession::class);
    }

    protected function status(): Attribute
    {
        return Attribute::get(function () {
            $now = Carbon::now();

            if (! $this->start_date || ! $this->end_date) {
                return null;
            }

            $startDate = Carbon::parse($this->start_date)->startOfDay();
            $endDate = Carbon::parse($this->end_date)->endOfDay();

            if ($now->lt($startDate)) {
                return StressCheckPeriodStatus::NotStarted->value;
            }

            if ($now->between($startDate, $endDate)) {
                return StressCheckPeriodStatus::InProgress->value;
            }

            return StressCheckPeriodStatus::Finished->value;
        });
    }
}
