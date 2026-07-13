<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_leagues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sportsdb_id')->unique();
            $table->string('sport_slug', 32)->index();
            $table->string('name');
            $table->string('badge_url')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sports_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sportsdb_id')->unique();
            $table->foreignId('sports_league_id')->nullable()->constrained('sports_leagues')->nullOnDelete();
            $table->string('sport_slug', 32)->index();
            $table->string('name');
            $table->string('league_name')->nullable();
            $table->date('event_date')->nullable()->index();
            $table->string('event_time', 32)->nullable();
            $table->string('status', 64)->nullable();
            $table->string('home_team')->nullable();
            $table->string('away_team')->nullable();
            $table->integer('home_score')->nullable();
            $table->integer('away_score')->nullable();
            $table->string('venue')->nullable();
            $table->string('country')->nullable();
            $table->string('thumb_url')->nullable();
            $table->text('result_text')->nullable();
            $table->boolean('is_major')->default(false)->index();
            $table->string('series', 64)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->index(['sport_slug', 'event_date']);
        });

        Schema::create('sports_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sports_league_id')->constrained('sports_leagues')->cascadeOnDelete();
            $table->string('season', 32)->nullable();
            $table->json('rows');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['sports_league_id', 'season']);
        });

        Schema::create('sports_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('sportsdb');
            $table->string('job', 64);
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('calls_used')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['job', 'status']);
        });

        Schema::create('sports_home_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique()->default('default');
            $table->json('payload');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_home_snapshots');
        Schema::dropIfExists('sports_sync_runs');
        Schema::dropIfExists('sports_standings');
        Schema::dropIfExists('sports_events');
        Schema::dropIfExists('sports_leagues');
    }
};
