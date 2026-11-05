<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_project_retention_backfills', function (Blueprint $table): void {
            $table->string('workspace_id', 128)->primary();
            $table->unsignedBigInteger('through_revision_id');
            $table->unsignedBigInteger('last_revision_id');
            $table->unsignedBigInteger('processed_revisions');
            $table->timestamps();
        });
        Schema::table('music_project_revisions', function (Blueprint $table): void {
            $table->index(['workspace_id', 'id'], 'music_revision_retention_scan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_project_retention_backfills');
        Schema::table('music_project_revisions', function (Blueprint $table): void {
            $table->dropIndex('music_revision_retention_scan');
        });
    }
};
