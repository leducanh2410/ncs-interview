<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * One row per line of Japan Post's KEN_ALL file, so a postal code can be
     * derived from an address (and an address from a postal code) without an
     * external service. `city_key`/`town_key` hold the normalized forms used
     * for matching; `city`/`town` keep the original text for display.
     */
    public function up(): void
    {
        Schema::create('japan_postal_codes', function (Blueprint $table) {
            $table->id();
            $table->char('postal_code', 7);
            $table->string('prefecture', 10);
            $table->string('city', 100);
            $table->string('city_key', 100);
            // Empty for city-level rows (the "以下に掲載がない場合" default and "〜一円")
            $table->string('town', 100)->default('');
            $table->string('town_key', 100)->default('');
            // The parenthetical qualifier of the town, e.g. "１〜１９丁目" or "次のビルを除く";
            // some are long enumerations of 番地, hence text
            $table->text('town_detail')->nullable();
            $table->boolean('is_city_default')->default(false);
            // sha256 over the identifying columns; the natural key is too wide for a unique index
            $table->char('row_hash', 64)->unique();
            $table->string('import_batch', 32);
            $table->timestamps();

            $table->index('postal_code');
            $table->index(['prefecture', 'city_key']);
            $table->index('import_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('japan_postal_codes');
    }
};
