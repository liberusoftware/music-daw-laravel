<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $uploads = DB::table('music_media_uploads as uploads')
                ->where(fn ($query) => $query->whereIn('status', ['uploading', 'ready'])
                    ->orWhere(fn ($cancelled) => $cancelled->where('status', 'cancelled')->whereExists(fn ($chunks) => $chunks->selectRaw('1')->from('music_media_upload_chunks as chunks')->whereColumn('chunks.upload_id', 'uploads.id'))))
                ->select(['uploads.id', 'uploads.workspace_id'])->selectRaw('uploads.total_bytes * 2, ?', [now('UTC')->format('Y-m-d H:i:s')]);
            DB::table('music_media_upload_reservations')->insertUsing(['upload_id', 'workspace_id', 'reserved_bytes', 'created_at'], $uploads);
            $usage = DB::table('music_media_upload_reservations')->select('workspace_id')->selectRaw('SUM(reserved_bytes)')->groupBy('workspace_id');
            DB::table('music_media_workspace_usage')->insertUsing(['workspace_id', 'reserved_bytes'], $usage);
        });
    }

    public function down(): void {}
};
