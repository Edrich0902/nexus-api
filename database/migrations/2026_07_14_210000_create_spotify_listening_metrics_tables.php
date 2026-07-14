<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_audio_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spotify_track_id')->unique()->constrained('spotify_tracks')->cascadeOnDelete();
            $table->string('provider', 32)->default('reccobeats');
            $table->string('provider_track_id')->nullable()->index();
            $table->string('isrc', 32)->nullable();
            $table->decimal('acousticness', 8, 6)->nullable();
            $table->decimal('danceability', 8, 6)->nullable();
            $table->decimal('energy', 8, 6)->nullable();
            $table->decimal('instrumentalness', 8, 6)->nullable();
            $table->tinyInteger('key')->nullable();
            $table->decimal('liveness', 8, 6)->nullable();
            $table->decimal('loudness', 8, 4)->nullable();
            $table->tinyInteger('mode')->nullable();
            $table->decimal('speechiness', 8, 6)->nullable();
            $table->decimal('tempo', 8, 3)->nullable();
            $table->decimal('valence', 8, 6)->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('fail_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('spotify_listen_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spotify_track_id')->constrained('spotify_tracks')->cascadeOnDelete();
            $table->string('spotify_id');
            $table->timestamp('started_at');
            $table->unsignedInteger('last_progress_ms')->default(0);
            $table->unsignedInteger('max_progress_ms')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('features_requested_at')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'spotify_id', 'status']);
        });

        Schema::create('spotify_listen_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spotify_track_id')->constrained('spotify_tracks')->cascadeOnDelete();
            $table->foreignId('session_id')->unique()->constrained('spotify_listen_sessions')->cascadeOnDelete();
            $table->decimal('weight', 4, 2);
            $table->unsignedInteger('listened_ms');
            $table->timestamp('played_at');
            $table->timestamps();

            $table->index(['user_id', 'played_at']);
        });

        Schema::create('spotify_listening_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('engage_progress_ms')->default(30_000);
            $table->decimal('engage_ratio', 4, 3)->default(0.250);
            $table->decimal('full_listen_ratio', 4, 3)->default(0.500);
            $table->boolean('auto_queue_enabled')->default(false);
            $table->unsignedTinyInteger('auto_queue_min_upcoming')->default(3);
            $table->unsignedTinyInteger('auto_queue_batch')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spotify_listening_settings');
        Schema::dropIfExists('spotify_listen_samples');
        Schema::dropIfExists('spotify_listen_sessions');
        Schema::dropIfExists('track_audio_features');
    }
};
