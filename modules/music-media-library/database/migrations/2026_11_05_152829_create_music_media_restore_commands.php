<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_restore_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('asset_id')->constrained('music_media_uploads')->restrictOnDelete();
            $table->foreignUuid('source_upload_id')->constrained('music_media_uploads')->restrictOnDelete();
            $table->char('command_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('actor_id', 128);
            $table->timestamp('created_at');
            $table->unique(['asset_id', 'command_hash'], 'music_media_restore_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_restore_commands');
    }
};
