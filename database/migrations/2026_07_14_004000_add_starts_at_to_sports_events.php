<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_events', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable()->after('event_time');
            $table->index('starts_at');
            $table->index(['sport_slug', 'starts_at']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::table('sports_events')
                ->whereNotNull('event_date')
                ->orderBy('id')
                ->each(function (object $row): void {
                    $time = is_string($row->event_time) && $row->event_time !== ''
                        ? $row->event_time
                        : '00:00:00';
                    $startsAt = "{$row->event_date} {$time}";
                    DB::table('sports_events')
                        ->where('id', $row->id)
                        ->update(['starts_at' => $startsAt]);
                });
        } else {
            DB::statement("
                UPDATE sports_events
                SET starts_at = CASE
                    WHEN event_date IS NULL THEN NULL
                    WHEN event_time IS NULL OR event_time = '' THEN CONCAT(event_date, ' 00:00:00')
                    ELSE CONCAT(event_date, ' ', event_time)
                END
                WHERE event_date IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::table('sports_events', function (Blueprint $table) {
            $table->dropIndex(['sport_slug', 'starts_at']);
            $table->dropIndex(['starts_at']);
            $table->dropColumn('starts_at');
        });
    }
};
