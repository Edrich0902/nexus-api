<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sports_events', function (Blueprint $table) {
            $table->string('home_badge_url')->nullable()->after('away_team');
            $table->string('away_badge_url')->nullable()->after('home_badge_url');
            $table->string('league_badge_url')->nullable()->after('away_badge_url');
        });
    }

    public function down(): void
    {
        Schema::table('sports_events', function (Blueprint $table) {
            $table->dropColumn(['home_badge_url', 'away_badge_url', 'league_badge_url']);
        });
    }
};
