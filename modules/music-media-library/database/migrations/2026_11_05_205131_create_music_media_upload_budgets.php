<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_workspace_usage', function (Blueprint $table): void {
            $table->string('workspace_id', 128)->primary();
            $table->unsignedBigInteger('reserved_bytes');
        });
        Schema::create('music_media_upload_reservations', function (Blueprint $table): void {
            $table->foreignUuid('upload_id')->primary()->constrained('music_media_uploads')->restrictOnDelete();
            $table->string('workspace_id', 128)->index();
            $table->unsignedBigInteger('reserved_bytes');
            $table->dateTime('created_at');
            $table->dateTime('released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_upload_reservations');
        Schema::dropIfExists('music_media_workspace_usage');
    }
};
