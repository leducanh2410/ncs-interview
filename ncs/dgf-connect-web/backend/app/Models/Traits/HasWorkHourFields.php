<?php

namespace App\Models\Traits;

trait HasWorkHourFields
{
    public function getWorkHoursUp1Attribute(): string
    {
        $nightMinutes = $this->getRawOriginal('work_hours_night', 0);
        $overtimeMinutes = $this->getRawOriginal('work_hours_overtime', 0);

        return $this->formatHour($nightMinutes + $overtimeMinutes);
    }

    protected function getWorkHoursAttribute(): string
    {
        return $this->formatHour($this->work_minutes);
    }

    protected function getWorkHoursNormalAttribute(): string
    {
        return $this->formatHour($this->work_minutes_normal);
    }

    protected function getWorkHoursNightAttribute(): string
    {
        return $this->formatHour($this->work_minutes_night);
    }

    protected function getWorkHoursOvertimeAttribute(): string
    {
        return $this->formatHour($this->work_minutes_overtime);
    }

    protected function getWorkHoursNightOvertimeAttribute(): string
    {
        return $this->formatHour($this->work_minutes_night_overtime);
    }

    private function formatHour(?int $value): string
    {
        if (!isset($value)) {
            return '';
        }

        return number_format($value / 60, 2);
    }
}
