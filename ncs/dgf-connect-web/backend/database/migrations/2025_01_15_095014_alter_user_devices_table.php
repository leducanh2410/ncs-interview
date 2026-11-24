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
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('platform')->after('name');
            $table->string('device_id')->after('user_id');
            $table->string('fcm_token')->after('secret_token');
            $table->string('secret_token')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->removeColumn('platform');
            $table->removeColumn('fcm_token');
            $table->removeColumn('device_id');
            $table->string('secret_token')->unique()->change();
        });
    }
};
