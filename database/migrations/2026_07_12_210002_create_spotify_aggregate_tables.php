<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spotify_artists', function (Blueprint $table) {
            $table->id();
            $table->string('spotify_id')->unique();
            $table->string('name');
            $table->json('genres')->nullable();
            $table->json('images')->nullable();
            $table->string('external_url')->nullable();
            $table->timestamps();
        });

        Schema::create('spotify_tracks', function (Blueprint $table) {
            $table->id();
            $table->string('spotify_id')->unique();
            $table->string('name');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('explicit')->default(false);
            $table->string('album_name')->nullable();
            $table->string('album_image_url')->nullable();
            $table->json('artists')->nullable();
            $table->string('external_url')->nullable();
            $table->string('uri')->nullable();
            $table->timestamps();
        });

        Schema::create('spotify_recently_played', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('spotify_track_id')->constrained('spotify_tracks')->cascadeOnDelete();
            $table->timestamp('played_at');
            $table->string('context_uri')->nullable();
            $table->string('context_type')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'spotify_track_id', 'played_at'], 'spotify_recent_user_track_played_unique');
            $table->index(['user_id', 'played_at']);
        });

        Schema::create('spotify_top_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('time_range', 16);
            $table->unsignedSmallInteger('rank');
            $table->foreignId('spotify_artist_id')->nullable()->constrained('spotify_artists')->nullOnDelete();
            $table->foreignId('spotify_track_id')->nullable()->constrained('spotify_tracks')->nullOnDelete();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'type', 'time_range', 'rank'], 'spotify_top_user_type_range_rank_unique');
            $table->index(['user_id', 'type', 'time_range']);
        });

        Schema::create('spotify_playlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('spotify_id')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('public')->default(false);
            $table->boolean('collaborative')->default(false);
            $table->boolean('is_owner')->default(false);
            $table->string('image_url')->nullable();
            $table->string('snapshot_id')->nullable();
            $table->string('uri')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'spotify_id']);
        });

        Schema::create('spotify_playlist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spotify_playlist_id')->constrained('spotify_playlists')->cascadeOnDelete();
            $table->foreignId('spotify_track_id')->nullable()->constrained('spotify_tracks')->nullOnDelete();
            $table->unsignedInteger('position');
            $table->timestamp('added_at')->nullable();
            $table->timestamps();

            $table->unique(['spotify_playlist_id', 'position']);
        });

        Schema::create('spotify_taste_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamp('computed_at');
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spotify_taste_snapshots');
        Schema::dropIfExists('spotify_playlist_items');
        Schema::dropIfExists('spotify_playlists');
        Schema::dropIfExists('spotify_top_items');
        Schema::dropIfExists('spotify_recently_played');
        Schema::dropIfExists('spotify_tracks');
        Schema::dropIfExists('spotify_artists');
    }
};
