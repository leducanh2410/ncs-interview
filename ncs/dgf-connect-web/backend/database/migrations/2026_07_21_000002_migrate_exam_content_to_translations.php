<?php

use App\Enums\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private const TRANSLATABLE_MAP = [
        'exams' => ['type' => 'App\\Models\\Exam', 'column' => 'title'],
        'questions' => ['type' => 'App\\Models\\Question', 'column' => 'content'],
        'question_options' => ['type' => 'App\\Models\\QuestionOption', 'column' => 'content'],
    ];

    /**
     * Backfill the existing single-language value into every supported
     * language slot so exams already published keep displaying content
     * (in the language they were originally written in) until an admin
     * re-edits them with real per-language translations.
     */
    public function up(): void
    {
        $langs = Locale::values();

        foreach (self::TRANSLATABLE_MAP as $table => $config) {
            DB::table($table)
                ->select('id', $config['column'])
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($config, $langs) {
                    $now = now();
                    $inserts = [];
                    foreach ($rows as $row) {
                        $value = $row->{$config['column']};
                        if ($value === null) {
                            continue;
                        }
                        foreach ($langs as $lang) {
                            $inserts[] = [
                                'translatable_type' => $config['type'],
                                'translatable_id' => $row->id,
                                'lang' => $lang,
                                'value' => $value,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                        }
                    }
                    if (!empty($inserts)) {
                        DB::table('exam_translations')->insert($inserts);
                    }
                });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TRANSLATABLE_MAP as $config) {
            DB::table('exam_translations')->where('translatable_type', $config['type'])->delete();
        }
    }
};
