<?php

use App\Models\WorkplaceShift;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $workplaceShifts = WorkplaceShift::query()
            ->where('start_time', '>=', '24:00')
            ->orWhere('end_time', '>=', '24:00')
            ->orWhere('start_break_time', '>=', '24:00')
            ->orWhere('end_break_time', '>=', '24:00')
            ->get();

        foreach ($workplaceShifts as $workplaceShift) {
            foreach (['start_time', 'end_time', 'start_break_time', 'end_break_time'] as $field) {
                if ($workplaceShift->{$field} && $workplaceShift->{$field} >= '24:00') {
                    $parts = explode(':', $workplaceShift->{$field});
                    $hour = (int) $parts[0] - 24;

                    $workplaceShift->{$field} = "{$hour}:{$parts[1]}";
                }
            }

            $workplaceShift->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
