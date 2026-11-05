<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    public function up(): void
    {
        // Historical timestamps may use another timezone. Start a full new UTC
        // grace period instead of making existing cancelled bytes eligible early.
        DB::table('music_media_uploads')->where('status', 'cancelled')->whereNull('cancelled_at')->update(['cancelled_at' => now('UTC')]);
    }

    public function down(): void {}
};
