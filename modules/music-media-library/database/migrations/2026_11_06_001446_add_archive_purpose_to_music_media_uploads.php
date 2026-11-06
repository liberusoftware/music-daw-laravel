<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->string('purpose', 16)->default('audio');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('music_media_uploads')->where('purpose', 'archive')->where('status', 'uploading')->exists()) {
            throw new LogicException('Cancel active archive inputs before removing their purpose metadata.');
        }

        Schema::table('music_media_uploads', function (Blueprint $table): void {
            $table->dropColumn('purpose');
        });
    }
};
