<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_projects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 128)->index();
            $table->unsignedBigInteger('version');
            $table->boolean('template');
        });
        Schema::create('music_project_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('project_id')->constrained('music_projects')->restrictOnDelete();
            $table->string('workspace_id', 128);
            $table->string('title');
            $table->unsignedBigInteger('version');
            $table->longText('document');
            $table->char('checksum', 64);
            $table->boolean('autosave');
            $table->boolean('template');
            $table->string('actor_id', 128);
            $table->string('idempotency_key', 128);
            $table->char('fingerprint', 64);
            $table->timestamp('created_at');
            $table->unique(['project_id', 'version']);
            $table->unique(['project_id', 'idempotency_key']);
            $table->index(['workspace_id', 'project_id', 'version'], 'music_revision_workspace_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_project_revisions');
        Schema::dropIfExists('music_projects');
    }
};
