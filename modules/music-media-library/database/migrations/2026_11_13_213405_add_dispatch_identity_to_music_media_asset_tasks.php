<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        foreach (['music_media_asset_tasks', 'music_media_trash_recovery_tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('dispatch_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['music_media_asset_tasks', 'music_media_trash_recovery_tasks'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('dispatch_id');
            });
        }
    }
};
