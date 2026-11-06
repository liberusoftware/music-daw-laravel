<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('music_media_archive_import_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128);
            $table->string('actor_id', 128);
            $table->foreignUuid('input_id')->constrained('music_media_uploads')->restrictOnDelete();
            $table->uuid('pack_id');
            $table->json('asset_map');
            $table->char('fingerprint', 64);
            $table->string('status', 16);
            $table->boolean('cancel_requested')->default(false);
            $table->unsignedInteger('completed_assets')->default(0);
            $table->unsignedInteger('total_assets');
            $table->unsignedInteger('attempt')->default(0);
            $table->uuid('lease_nonce')->nullable();
            $table->uuid('dispatch_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedBigInteger('result_version')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'actor_id', 'status']);
            $table->index(['input_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('music_media_archive_import_tasks');
    }
};
