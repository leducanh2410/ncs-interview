<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $groups = DB::table('groups')->whereNotNull('predicate')->get();

        foreach ($groups as $group) {
            $predicate = json_decode($group->predicate, true);

            if (!is_array($predicate)) {
                continue;
            }

            $converted = array_map(function (array $clause): array {
                if (array_key_exists('values', $clause)) {
                    return $clause;
                }

                $value = $clause['value'] ?? null;

                return [
                    'field' => $clause['field'] ?? null,
                    'values' => is_string($value) && $value !== '' ? [$value] : [],
                ];
            }, $predicate);

            DB::table('groups')
                ->where('id', $group->id)
                ->update(['predicate' => json_encode($converted)]);
        }
    }

    public function down(): void
    {
        $groups = DB::table('groups')->whereNotNull('predicate')->get();

        foreach ($groups as $group) {
            $predicate = json_decode($group->predicate, true);

            if (!is_array($predicate)) {
                continue;
            }

            $converted = array_map(function (array $clause): array {
                if (!array_key_exists('values', $clause)) {
                    return $clause;
                }

                $values = $clause['values'] ?? [];

                return [
                    'field' => $clause['field'] ?? null,
                    'value' => $values[0] ?? null,
                ];
            }, $predicate);

            DB::table('groups')
                ->where('id', $group->id)
                ->update(['predicate' => json_encode($converted)]);
        }
    }
};
