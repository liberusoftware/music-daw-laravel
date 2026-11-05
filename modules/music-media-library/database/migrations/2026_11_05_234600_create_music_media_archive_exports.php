<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_archive_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128);
            $table->string('actor_id', 128);
            $table->foreignUuid('pack_id')->constrained('music_media_packs')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->json('transfer')->nullable();
            $table->string('expires_at', 32)->nullable();
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->timestamps();
            $table->index(['workspace_id', 'actor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_archive_exports');
    }
};
