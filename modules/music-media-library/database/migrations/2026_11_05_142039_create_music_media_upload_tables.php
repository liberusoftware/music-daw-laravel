<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128)->index();
            $table->string('actor_id', 128);
            $table->string('name');
            $table->unsignedBigInteger('total_bytes');
            $table->unsignedBigInteger('received_bytes');
            $table->char('sha256', 64);
            $table->char('fingerprint', 64);
            $table->string('status', 20);
            $table->text('provenance');
            $table->text('metadata')->nullable();
            $table->uuid('duplicate_of')->nullable();
            $table->string('blob_key')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'sha256', 'status'], 'music_media_duplicate_index');
        });
        Schema::create('music_media_upload_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('upload_id')->constrained('music_media_uploads')->restrictOnDelete();
            $table->unsignedBigInteger('offset_bytes');
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->string('object_key');
            $table->unique(['upload_id', 'offset_bytes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_media_upload_chunks');
        Schema::dropIfExists('music_media_uploads');
    }
};
