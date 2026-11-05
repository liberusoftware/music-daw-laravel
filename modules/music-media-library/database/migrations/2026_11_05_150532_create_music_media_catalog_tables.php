<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('music_media_annotations', function (Blueprint $table): void {
            $table->foreignUuid('asset_id')->primary()->constrained('music_media_uploads')->restrictOnDelete();
            $table->string('name');
            $table->string('search_name');
            $table->unsignedBigInteger('version');
            $table->text('tags');
        });
        Schema::create('music_media_tags', function (Blueprint $table): void {
            $table->foreignUuid('asset_id')->constrained('music_media_annotations', 'asset_id')->restrictOnDelete();
            $table->string('tag', 64);
            $table->char('tag_hash', 64);
            $table->primary(['asset_id', 'tag_hash']);
            $table->index(['tag_hash', 'asset_id']);
        });
        Schema::create('music_media_annotation_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('asset_id')->constrained('music_media_annotations', 'asset_id')->restrictOnDelete();
            $table->string('command_key', 128);
            $table->char('fingerprint', 64);
            $table->text('result');
            $table->unique(['asset_id', 'command_key'], 'music_media_annotation_replay');
        });
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->string('search_name')->nullable();
            $table->index(['workspace_id', 'status', 'created_at', 'id'], 'music_media_catalog_index');
        });
        DB::table('music_media_uploads')->select(['id', 'name'])->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('music_media_uploads')->where('id', $row->id)->update(['search_name' => Str::lower($row->name)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dropIndex('music_media_catalog_index');
            $table->dropColumn('search_name');
        });
        Schema::dropIfExists('music_media_annotation_commands');
        Schema::dropIfExists('music_media_tags');
        Schema::dropIfExists('music_media_annotations');
    }
};
