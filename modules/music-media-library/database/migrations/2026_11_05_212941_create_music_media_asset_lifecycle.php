<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dateTime('trashed_at')->nullable();
            $table->dateTime('purge_after')->nullable();
            $table->index(['workspace_id', 'status', 'id'], 'music_media_lifecycle_index');
        });
        Schema::create('music_media_lifecycle_commands', function (Blueprint $table): void {
            $table->string('workspace_id', 128);
            $table->char('command_hash', 64);
            $table->char('fingerprint', 64);
            $table->text('result')->nullable();
            $table->primary(['workspace_id', 'command_hash']);
        });
        Schema::create('music_media_content_locks', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
        });
    }

    public function down(): void
    {
        // Audit tombstones cannot safely be made ready by rollback. Preserve
        // statuses and bytes; roll forward to recover a disabled installation.
        Schema::dropIfExists('music_media_content_locks');
        Schema::dropIfExists('music_media_lifecycle_commands');
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dropIndex('music_media_lifecycle_index');
            $table->dropColumn(['trashed_at', 'purge_after']);
        });
    }
};
