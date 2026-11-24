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
        // Drop existing foreign key constraints on role_user.team_id
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        // Drop existing foreign key constraints on permission_user.team_id
        Schema::table('permission_user', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        // Add new foreign key constraints pointing to workplaces table
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('workplaces')->onUpdate('cascade')->cascadeOnDelete();
        });

        Schema::table('permission_user', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('workplaces')->onUpdate('cascade')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop workplace foreign key constraints
        Schema::table('role_user', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        Schema::table('permission_user', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
        });

        // Restore companies foreign key constraints
        Schema::table('role_user', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('companies')->onUpdate('cascade')->cascadeOnDelete();
        });

        Schema::table('permission_user', function (Blueprint $table) {
            $table->foreign('team_id')->references('id')->on('companies')->onUpdate('cascade')->cascadeOnDelete();
        });
    }
};
