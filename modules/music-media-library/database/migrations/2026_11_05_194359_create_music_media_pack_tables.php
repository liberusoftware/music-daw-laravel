<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_packs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128)->index();
            $table->unsignedBigInteger('version');
        });
        Schema::create('music_media_pack_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('pack_id')->constrained('music_media_packs')->restrictOnDelete();
            $table->string('workspace_id', 128);
            $table->unsignedBigInteger('version');
            $table->string('name');
            $table->unsignedInteger('asset_count');
            $table->longText('document');
            $table->char('checksum', 64);
            $table->char('command_hash', 64);
            $table->char('fingerprint', 64);
            $table->string('actor_id', 128);
            $table->timestamp('created_at');
            $table->unique(['pack_id', 'version']);
            $table->unique(['pack_id', 'command_hash']);
            $table->index(['workspace_id', 'pack_id', 'version'], 'music_media_pack_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_pack_revisions');
        Schema::dropIfExists('music_media_packs');
    }
};
