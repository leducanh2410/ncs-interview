<?php

use App\Enums\NotificationTargetType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('target_type', 20)->default(NotificationTargetType::All->value)->after('type');
            $table->index('target_type');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['target_type']);
            $table->dropColumn('target_type');
        });
    }
};
