<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dateTime('cancelled_at')->nullable();
            $table->index(['workspace_id', 'status', 'cancelled_at'], 'music_media_quarantine_grace');
        });
    }

    public function down(): void
    {
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dropIndex('music_media_quarantine_grace');
            $table->dropColumn('cancelled_at');
        });
    }
};
