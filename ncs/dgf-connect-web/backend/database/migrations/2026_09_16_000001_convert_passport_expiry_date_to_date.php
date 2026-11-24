<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Bounds of the MySQL DATE type (`1000-01-01` to `9999-12-31`).
     */
    private const MIN_DATE_YEAR = 1000;

    private const MAX_DATE_YEAR = 9999;

    /**
     * `passport_expiry_date` was the only expiry column stored as text, while
     * `student_card_expiry_date` and `residence_expiry_date_1..3` are real DATE columns.
     *
     * That inconsistency is not cosmetic: MySQL compares a VARCHAR column against a string
     * literal as text, so a query written for the DATE columns (`'2026/09/30'`, which MySQL
     * happily casts for them) silently never matched a passport — which is exactly how the
     * passport expiry reminder went out for every document except the passport.
     *
     * Every write path already normalises the value to `Y-m-d` (the request validation rules,
     * the OCR normaliser and the CrossStaff sync), so the conversion is lossless for anything
     * written through the app. Older or hand-edited rows are normalised here first, because
     * MySQL in strict mode rejects the ALTER outright if a single value is not a valid date.
     */
    public function up(): void
    {
        $unparseable = 0;

        // Distinct values, not rows: a date repeats across employees, and this keeps the
        // pass over a table with one row per user down to a handful of updates.
        $values = DB::table('user_infos')
            ->whereNotNull('passport_expiry_date')
            ->distinct()
            ->pluck('passport_expiry_date');

        foreach ($values as $value) {
            $normalized = $this->normalize($value);
            if ($normalized === $value) {
                continue;
            }

            if ($normalized === null && trim((string) $value) !== '') {
                $unparseable++;
                Log::warning('[convert_passport_expiry_date_to_date] Dropping unparseable or out-of-range value', [
                    'value' => $value,
                ]);
            }

            DB::table('user_infos')
                ->where('passport_expiry_date', $value)
                ->update(['passport_expiry_date' => $normalized]);
        }

        if ($unparseable > 0) {
            Log::warning(
                "[convert_passport_expiry_date_to_date] Cleared {$unparseable} unparseable or out-of-range value(s)."
            );
        }

        Schema::table('user_infos', function (Blueprint $table) {
            $table->date('passport_expiry_date')->nullable()->change();
        });
    }

    /**
     * Reverses the column type only. Values stay in `Y-m-d`, which is the format the
     * application wrote anyway; the original text of a malformed row is not recoverable.
     */
    public function down(): void
    {
        Schema::table('user_infos', function (Blueprint $table) {
            $table->string('passport_expiry_date')->nullable()->change();
        });
    }

    /**
     * `Carbon::parse('')` returns the current time rather than failing, so the empty string
     * has to be caught before it turns every blank passport field into today's date.
     *
     * A successful parse is not enough either: the all-zero date some systems use for "not
     * entered" parses to `-0001-11-30`, which itself parses back just fine, yet neither is a
     * value the DATE column will take. Anything outside the type's range is dropped as well.
     */
    private function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = $this->parse($value);
        if ($date === null || $date->year < self::MIN_DATE_YEAR || $date->year > self::MAX_DATE_YEAR) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function parse(string $value): ?Carbon
    {
        // Checked before the lenient parse: `Carbon::parse()` reads an ambiguous
        // `2026/09/30`-style value as m/d/Y, and `hasFormat()` rejects overflowing
        // components that `createFromFormat()` would silently roll over.
        foreach (['Y-m-d', 'Y/m/d'] as $format) {
            if (Carbon::hasFormat($value, $format)) {
                return Carbon::createFromFormat($format, $value);
            }
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
};
