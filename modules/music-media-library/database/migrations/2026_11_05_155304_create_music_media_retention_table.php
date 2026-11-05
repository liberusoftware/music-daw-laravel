<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_retention', function (Blueprint $table): void {
            $table->foreignUuid('asset_id')->constrained('music_media_uploads')->restrictOnDelete();
            $table->string('workspace_id', 128);
            $table->char('reference_hash', 64);
            $table->string('reference_kind', 32);
            $table->string('reference_id', 200);
            $table->string('actor_id', 128);
            $table->timestamp('created_at');
            $table->primary(['asset_id', 'reference_hash']);
            $table->index(['workspace_id', 'reference_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_retention');
    }
};
