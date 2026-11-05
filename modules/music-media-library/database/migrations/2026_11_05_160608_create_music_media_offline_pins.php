<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('music_media_retention', function (Blueprint $table): void {
            $table->dateTime('expires_at')->nullable();
            $table->index(['workspace_id', 'expires_at']);
        });
        Schema::create('music_media_offline_pins', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128);
            $table->string('actor_id', 128);
            $table->unsignedBigInteger('version');
            $table->text('assets');
            // Preserve the public UTC timestamp exactly in command results.
            $table->string('expires_at', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('music_media_offline_pin_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('pin_id')->constrained('music_media_offline_pins')->restrictOnDelete();
            $table->char('command_hash', 64);
            $table->char('fingerprint', 64);
            $table->text('result');
            $table->timestamp('created_at');
            $table->unique(['pin_id', 'command_hash'], 'music_media_offline_pin_replay');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_offline_pin_commands');
        Schema::dropIfExists('music_media_offline_pins');
        Schema::table('music_media_retention', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'expires_at']);
            $table->dropColumn('expires_at');
        });
    }
};
