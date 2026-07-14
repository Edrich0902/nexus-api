<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('f1_meetings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->unique();
            $table->unsignedSmallInteger('year')->index();
            $table->string('meeting_name');
            $table->string('meeting_official_name')->nullable();
            $table->string('circuit_short_name')->nullable();
            $table->unsignedInteger('circuit_key')->nullable();
            $table->string('circuit_image')->nullable();
            $table->string('circuit_info_url')->nullable();
            $table->string('circuit_type', 64)->nullable();
            $table->string('country_code', 8)->nullable();
            $table->string('country_name')->nullable();
            $table->string('country_flag')->nullable();
            $table->unsignedInteger('country_key')->nullable();
            $table->string('location')->nullable();
            $table->string('gmt_offset', 16)->nullable();
            $table->timestamp('date_start')->nullable()->index();
            $table->timestamp('date_end')->nullable()->index();
            $table->boolean('is_cancelled')->default(false);
            $table->timestamps();
        });

        Schema::create('f1_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('session_key')->unique();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedSmallInteger('year')->index();
            $table->string('session_name');
            $table->string('session_type', 64)->nullable()->index();
            $table->string('circuit_short_name')->nullable();
            $table->string('country_name')->nullable();
            $table->string('location')->nullable();
            $table->string('gmt_offset', 16)->nullable();
            $table->timestamp('date_start')->nullable()->index();
            $table->timestamp('date_end')->nullable()->index();
            $table->boolean('is_cancelled')->default(false);
            $table->timestamp('detail_synced_at')->nullable();
            $table->timestamp('replay_synced_at')->nullable();
            $table->string('replay_status', 32)->nullable(); // pending|ready|failed
            $table->text('replay_error')->nullable();
            $table->timestamps();

            $table->index(['meeting_key', 'date_start']);
        });

        Schema::create('f1_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->string('broadcast_name')->nullable();
            $table->string('full_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('name_acronym', 8)->nullable();
            $table->string('team_name')->nullable();
            $table->string('team_colour', 12)->nullable();
            $table->string('headshot_url')->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'driver_number']);
        });

        Schema::create('f1_session_results', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->unsignedSmallInteger('position')->nullable();
            $table->json('duration')->nullable();
            $table->json('gap_to_leader')->nullable();
            $table->unsignedSmallInteger('number_of_laps')->nullable();
            $table->boolean('dnf')->default(false);
            $table->boolean('dns')->default(false);
            $table->boolean('dsq')->default(false);
            $table->timestamps();

            $table->unique(['session_key', 'driver_number']);
        });

        Schema::create('f1_starting_grids', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->unsignedSmallInteger('position')->nullable();
            $table->decimal('lap_duration', 10, 3)->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'driver_number']);
        });

        Schema::create('f1_championship_drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->unsignedSmallInteger('position_current')->nullable();
            $table->unsignedSmallInteger('position_start')->nullable();
            $table->decimal('points_current', 8, 1)->nullable();
            $table->decimal('points_start', 8, 1)->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'driver_number']);
        });

        Schema::create('f1_championship_teams', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('year')->index();
            $table->string('team_name');
            $table->unsignedSmallInteger('position_current')->nullable();
            $table->unsignedSmallInteger('position_start')->nullable();
            $table->decimal('points_current', 8, 1)->nullable();
            $table->decimal('points_start', 8, 1)->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'team_name']);
        });

        Schema::create('f1_laps', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->unsignedSmallInteger('lap_number');
            $table->timestamp('date_start')->nullable();
            $table->decimal('lap_duration', 10, 3)->nullable();
            $table->decimal('duration_sector_1', 10, 3)->nullable();
            $table->decimal('duration_sector_2', 10, 3)->nullable();
            $table->decimal('duration_sector_3', 10, 3)->nullable();
            $table->unsignedSmallInteger('i1_speed')->nullable();
            $table->unsignedSmallInteger('i2_speed')->nullable();
            $table->unsignedSmallInteger('st_speed')->nullable();
            $table->boolean('is_pit_out_lap')->default(false);
            $table->json('segments_sector_1')->nullable();
            $table->json('segments_sector_2')->nullable();
            $table->json('segments_sector_3')->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'driver_number', 'lap_number']);
        });

        Schema::create('f1_pits', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->timestamp('date')->nullable();
            $table->unsignedSmallInteger('lap_number')->nullable();
            $table->decimal('lane_duration', 10, 3)->nullable();
            $table->decimal('stop_duration', 10, 3)->nullable();
            $table->timestamps();

            $table->index(['session_key', 'driver_number']);
        });

        Schema::create('f1_stints', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->unsignedSmallInteger('stint_number');
            $table->string('compound', 32)->nullable();
            $table->unsignedSmallInteger('lap_start')->nullable();
            $table->unsignedSmallInteger('lap_end')->nullable();
            $table->unsignedSmallInteger('tyre_age_at_start')->nullable();
            $table->timestamps();

            $table->unique(['session_key', 'driver_number', 'stint_number']);
        });

        Schema::create('f1_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->timestamp('date')->nullable()->index();
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->index(['session_key', 'driver_number', 'date']);
        });

        Schema::create('f1_race_control', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->timestamp('date')->nullable()->index();
            $table->string('category', 64)->nullable();
            $table->string('flag', 64)->nullable();
            $table->string('scope', 64)->nullable();
            $table->unsignedSmallInteger('driver_number')->nullable();
            $table->unsignedSmallInteger('lap_number')->nullable();
            $table->unsignedSmallInteger('sector')->nullable();
            $table->unsignedTinyInteger('qualifying_phase')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['session_key', 'date']);
        });

        Schema::create('f1_weather', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->timestamp('date')->nullable()->index();
            $table->decimal('air_temperature', 5, 1)->nullable();
            $table->decimal('track_temperature', 5, 1)->nullable();
            $table->unsignedSmallInteger('humidity')->nullable();
            $table->decimal('pressure', 7, 1)->nullable();
            $table->boolean('rainfall')->nullable();
            $table->unsignedSmallInteger('wind_direction')->nullable();
            $table->decimal('wind_speed', 5, 1)->nullable();
            $table->timestamps();

            $table->index(['session_key', 'date']);
        });

        Schema::create('f1_overtakes', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('meeting_key')->index();
            $table->unsignedInteger('session_key')->index();
            $table->timestamp('date')->nullable();
            $table->unsignedSmallInteger('overtaking_driver_number');
            $table->unsignedSmallInteger('overtaken_driver_number');
            $table->unsignedSmallInteger('position')->nullable();
            $table->timestamps();

            $table->index(['session_key', 'date']);
        });

        Schema::create('f1_location_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->timestamp('date', 3)->index();
            $table->integer('x');
            $table->integer('y');
            $table->integer('z')->nullable();
            $table->timestamps();

            $table->index(['session_key', 'driver_number', 'date']);
        });

        Schema::create('f1_car_data_samples', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('session_key')->index();
            $table->unsignedSmallInteger('driver_number');
            $table->timestamp('date', 3)->index();
            $table->unsignedSmallInteger('speed')->nullable();
            $table->unsignedSmallInteger('rpm')->nullable();
            $table->unsignedTinyInteger('n_gear')->nullable();
            $table->unsignedTinyInteger('throttle')->nullable();
            $table->unsignedTinyInteger('brake')->nullable();
            $table->unsignedTinyInteger('drs')->nullable();
            $table->timestamps();

            $table->index(['session_key', 'driver_number', 'date']);
        });

        Schema::create('f1_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32)->default('openf1');
            $table->string('job', 64);
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('calls_used')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['job', 'status']);
        });

        Schema::create('f1_home_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique()->default('default');
            $table->json('payload');
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('f1_home_snapshots');
        Schema::dropIfExists('f1_sync_runs');
        Schema::dropIfExists('f1_car_data_samples');
        Schema::dropIfExists('f1_location_samples');
        Schema::dropIfExists('f1_overtakes');
        Schema::dropIfExists('f1_weather');
        Schema::dropIfExists('f1_race_control');
        Schema::dropIfExists('f1_positions');
        Schema::dropIfExists('f1_stints');
        Schema::dropIfExists('f1_pits');
        Schema::dropIfExists('f1_laps');
        Schema::dropIfExists('f1_championship_teams');
        Schema::dropIfExists('f1_championship_drivers');
        Schema::dropIfExists('f1_starting_grids');
        Schema::dropIfExists('f1_session_results');
        Schema::dropIfExists('f1_drivers');
        Schema::dropIfExists('f1_sessions');
        Schema::dropIfExists('f1_meetings');
    }
};
