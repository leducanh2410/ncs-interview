<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Existing rows have no recorded provenance, so this backfills every one of them —
        // including actual filler content created by the old code — as is_fallback = false
        // (a genuine translation). This does not just reproduce the original content/bonus-line
        // locale mismatch for old filler rows; it permanently mislabels them as genuine
        // translations. Editing and re-saving the notification does not clear this: the modal
        // only skips rows with is_fallback = true, so an old filler row loads into the form as
        // real content and gets resubmitted as-is, keeping is_fallback = false. There is
        // currently no cleanup path other than an admin noticing and manually re-typing that
        // locale's translation. Accepted rather than backfilled more precisely, since old rows
        // carry no reliable way to tell filler apart from a genuine translation after the fact.
        Schema::table('notification_translations', function (Blueprint $table) {
            $table->boolean('is_fallback')->default(false)->after('content');
            // The language the fallback content is actually written in (e.g. 'en' when
            // copied from an admin-provided English translation, or the system base locale
            // when copied from the base title/content as-is). Null for genuine, non-fallback
            // rows. Lets consumers pick a language-consistent presentation for filler
            // content instead of either mislabeling it with the row's nominal `lang`, or
            // discarding it outright in favor of the base fields.
            $table->string('fallback_source_lang', 2)->nullable()->after('is_fallback');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_translations', function (Blueprint $table) {
            $table->dropColumn(['is_fallback', 'fallback_source_lang']);
        });
    }
};
