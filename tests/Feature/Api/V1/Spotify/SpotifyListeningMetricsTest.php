<?php

namespace Tests\Feature\Api\V1\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Jobs\Spotify\FetchTrackAudioFeaturesJob;
use App\Models\Integration\IntegrationConnection;
use App\Models\Spotify\SpotifyListenSample;
use App\Models\Spotify\SpotifyListenSession;
use App\Models\Spotify\TrackAudioFeatures;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpotifyListeningMetricsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
            'services.spotify.redirect' => 'http://127.0.0.1:80/spotify/callback',
            'services.reccobeats.base_url' => 'https://api.reccobeats.com',
            'services.rate_limits.spotify.max_wait_seconds' => 0,
        ]);

        $this->user = User::factory()->create();

        IntegrationConnection::query()->create([
            'user_id' => $this->user->id,
            'provider' => SpotifyIntegration::PROVIDER,
            'external_user_id' => 'spotify-user-1',
            'scopes' => implode(' ', app(SpotifyIntegration::class)->scopes()),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->user);
        Cache::flush();
    }

    public function test_heartbeat_stays_idle_before_threshold(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'trackIdle1',
            'progress_ms' => 5_000,
            'duration_ms' => 200_000,
            'is_playing' => true,
            'name' => 'Quiet Skip',
        ])
            ->assertOk()
            ->assertJsonPath('engaged', false)
            ->assertJsonPath('features_status', 'idle');

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('track_audio_features', 0);
    }

    public function test_heartbeat_fetches_features_after_threshold(): void
    {
        Http::fake([
            'api.reccobeats.com/v1/audio-features*' => Http::response([
                'content' => [[
                    'id' => 'uuid-1',
                    'href' => 'https://open.spotify.com/track/0VjIjW4GlUZAMYd2vXMi3b',
                    'acousticness' => 0.1,
                    'danceability' => 0.5,
                    'energy' => 0.73,
                    'instrumentalness' => 0.0,
                    'key' => 1,
                    'liveness' => 0.1,
                    'loudness' => -5.9,
                    'mode' => 1,
                    'speechiness' => 0.05,
                    'tempo' => 171.0,
                    'valence' => 0.33,
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => '0VjIjW4GlUZAMYd2vXMi3b',
            'progress_ms' => 35_000,
            'duration_ms' => 200_000,
            'is_playing' => true,
            'name' => 'Blinding Lights',
        ])
            ->assertOk()
            ->assertJsonPath('engaged', true)
            ->assertJsonPath('features_status', 'ready')
            ->assertJsonPath('features.energy', 0.73);

        $this->assertDatabaseHas('track_audio_features', [
            'provider' => 'reccobeats',
        ]);
    }

    public function test_heartbeat_returns_cached_features_when_ready(): void
    {
        Http::fake([
            'api.reccobeats.com/v1/audio-features*' => Http::response([
                'content' => [[
                    'id' => 'uuid-ready',
                    'href' => 'https://open.spotify.com/track/readyTrack1',
                    'energy' => 0.8,
                    'danceability' => 0.7,
                    'valence' => 0.5,
                    'tempo' => 120,
                    'acousticness' => 0.1,
                    'instrumentalness' => 0,
                    'key' => 0,
                    'liveness' => 0.1,
                    'loudness' => -6,
                    'mode' => 1,
                    'speechiness' => 0.05,
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'readyTrack1',
            'progress_ms' => 40_000,
            'duration_ms' => 180_000,
            'name' => 'Ready Song',
        ])
            ->assertOk()
            ->assertJsonPath('features_status', 'ready')
            ->assertJsonPath('features.energy', 0.8);

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'readyTrack1',
            'progress_ms' => 50_000,
            'duration_ms' => 180_000,
        ])
            ->assertOk()
            ->assertJsonPath('features_status', 'ready')
            ->assertJsonPath('features.energy', 0.8);
    }

    public function test_track_change_writes_weighted_sample(): void
    {
        Queue::fake();

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'sampleA',
            'progress_ms' => 100_000,
            'duration_ms' => 180_000,
            'name' => 'Song A',
        ])->assertOk();

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'sampleB',
            'progress_ms' => 1_000,
            'duration_ms' => 200_000,
            'name' => 'Song B',
        ])->assertOk();

        $this->assertDatabaseCount('spotify_listen_samples', 1);
        $sample = SpotifyListenSample::query()->firstOrFail();
        $this->assertSame(1.0, (float) $sample->weight);
    }

    public function test_fetch_job_stores_reccobeats_features(): void
    {
        Http::fake([
            'api.reccobeats.com/v1/audio-features*' => Http::response([
                'content' => [[
                    'id' => 'uuid-1',
                    'href' => 'https://open.spotify.com/track/0VjIjW4GlUZAMYd2vXMi3b',
                    'isrc' => 'USUG11904206',
                    'acousticness' => 0.1,
                    'danceability' => 0.5,
                    'energy' => 0.73,
                    'instrumentalness' => 0.0,
                    'key' => 1,
                    'liveness' => 0.1,
                    'loudness' => -5.9,
                    'mode' => 1,
                    'speechiness' => 0.05,
                    'tempo' => 171.0,
                    'valence' => 0.33,
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => '0VjIjW4GlUZAMYd2vXMi3b',
            'progress_ms' => 35_000,
            'duration_ms' => 200_000,
            'name' => 'Blinding Lights',
        ])->assertOk();

        $job = new FetchTrackAudioFeaturesJob(
            SpotifyListenSession::query()->firstOrFail()->spotify_track_id,
        );
        $job->handle(app(\App\Services\Spotify\TrackAudioFeaturesService::class));

        $this->assertDatabaseHas('track_audio_features', [
            'energy' => 0.73,
            'provider' => 'reccobeats',
        ]);

        $this->getJson('/api/v1/spotify/tracks/0VjIjW4GlUZAMYd2vXMi3b/features')
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('features.tempo', 171);
    }

    public function test_similar_recommendations_use_spotify_neighborhood_pool(): void
    {
        \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedNeighborhood1',
            'name' => 'Anchor Song',
            'uri' => 'spotify:track:seedNeighborhood1',
            'artists' => [['id' => 'artistPrimary1', 'name' => 'Primary Act']],
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistPrimary1/related-artists' => Http::response([
                'artists' => [
                    ['id' => 'artistRelated1', 'name' => 'Related Act'],
                ],
            ]),
            'api.spotify.com/v1/artists/artistPrimary1/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'sameArtistTop1',
                    'name' => 'Same Artist Hit',
                    'uri' => 'spotify:track:sameArtistTop1',
                    'duration_ms' => 180000,
                    'artists' => [['id' => 'artistPrimary1', 'name' => 'Primary Act']],
                    'album' => ['name' => 'A', 'images' => []],
                ]],
            ]),
            'api.spotify.com/v1/artists/artistRelated1/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'relatedTop1',
                    'name' => 'Related Hit',
                    'uri' => 'spotify:track:relatedTop1',
                    'duration_ms' => 190000,
                    'artists' => [['id' => 'artistRelated1', 'name' => 'Related Act']],
                    'album' => ['name' => 'B', 'images' => []],
                ]],
            ]),
            'api.reccobeats.com/*' => Http::response(['content' => []]),
        ]);

        $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedNeighborhood1&limit=5')
            ->assertOk()
            ->assertJsonPath('source', 'spotify_neighborhood')
            ->assertJsonPath('items.0.track.id', 'relatedTop1')
            ->assertJsonFragment(['id' => 'sameArtistTop1']);
    }

    public function test_similar_recommendations_diversify_via_genre_when_related_unavailable(): void
    {
        $seed = \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedGenreDiv1',
            'name' => '80s Anchor',
            'uri' => 'spotify:track:seedGenreDiv1',
            'artists' => [['id' => 'artistGenrePrim', 'name' => 'Primary 80s']],
        ]);
        TrackAudioFeatures::query()->create([
            'spotify_track_id' => $seed->id,
            'provider' => 'reccobeats',
            'provider_track_id' => 'uuid-genre-seed',
            'energy' => 0.7,
            'danceability' => 0.75,
            'valence' => 0.6,
            'tempo' => 118,
            'fetched_at' => now(),
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistGenrePrim/related-artists' => Http::response([
                'error' => ['message' => 'Forbidden'],
            ], 403),
            'api.spotify.com/v1/artists/artistGenrePrim' => Http::response([
                'id' => 'artistGenrePrim',
                'name' => 'Primary 80s',
                'genres' => ['new wave', 'synthpop'],
                'images' => [],
                'followers' => ['total' => 10],
                'popularity' => 50,
                'uri' => 'spotify:artist:artistGenrePrim',
                'external_urls' => ['spotify' => null],
            ]),
            'api.spotify.com/v1/artists/artistGenrePrim/top-tracks*' => Http::response([
                'tracks' => [
                    [
                        'id' => 'genrePrimaryA',
                        'name' => 'Primary A',
                        'uri' => 'spotify:track:genrePrimaryA',
                        'artists' => [['id' => 'artistGenrePrim', 'name' => 'Primary 80s']],
                        'album' => ['name' => 'A', 'images' => []],
                    ],
                    [
                        'id' => 'genrePrimaryB',
                        'name' => 'Primary B',
                        'uri' => 'spotify:track:genrePrimaryB',
                        'artists' => [['id' => 'artistGenrePrim', 'name' => 'Primary 80s']],
                        'album' => ['name' => 'B', 'images' => []],
                    ],
                ],
            ]),
            'api.spotify.com/v1/search*' => Http::response([
                'tracks' => [
                    'items' => [
                        [
                            'id' => 'genreOther1',
                            'name' => 'Other New Wave',
                            'uri' => 'spotify:track:genreOther1',
                            'duration_ms' => 200000,
                            'explicit' => false,
                            'artists' => [['id' => 'artistOtherWave', 'name' => 'Other Wave']],
                            'album' => ['name' => 'O', 'images' => []],
                        ],
                        [
                            'id' => 'genreOther2',
                            'name' => 'Other Synth',
                            'uri' => 'spotify:track:genreOther2',
                            'duration_ms' => 210000,
                            'explicit' => false,
                            'artists' => [['id' => 'artistOtherSynth', 'name' => 'Other Synth']],
                            'album' => ['name' => 'S', 'images' => []],
                        ],
                    ],
                ],
            ]),
            'api.reccobeats.com/v1/track/recommendation*' => Http::response(['content' => []]),
            'api.reccobeats.com/v1/audio-features*' => Http::response(['content' => []]),
        ]);

        $ids = collect(
            $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedGenreDiv1&limit=6')
                ->assertOk()
                ->json('items')
        )->pluck('track.id')->all();

        $this->assertContains('genreOther1', $ids);
        $this->assertContains('genreOther2', $ids);
        $primaryCount = count(array_intersect($ids, ['genrePrimaryA', 'genrePrimaryB']));
        $this->assertLessThan(count($ids), $primaryCount + 1);
    }

    public function test_similar_recommendations_fill_limit_when_only_same_artist_available(): void
    {
        \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedOnlyPrimary1',
            'name' => 'Solo Anchor',
            'uri' => 'spotify:track:seedOnlyPrimary1',
            'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistSolo1/related-artists' => Http::response([
                'artists' => [],
            ]),
            'api.spotify.com/v1/artists/artistSolo1/top-tracks*' => Http::response([
                'tracks' => [
                    [
                        'id' => 'soloA',
                        'name' => 'Solo A',
                        'uri' => 'spotify:track:soloA',
                        'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
                        'album' => ['name' => 'A', 'images' => []],
                    ],
                    [
                        'id' => 'soloB',
                        'name' => 'Solo B',
                        'uri' => 'spotify:track:soloB',
                        'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
                        'album' => ['name' => 'B', 'images' => []],
                    ],
                    [
                        'id' => 'soloC',
                        'name' => 'Solo C',
                        'uri' => 'spotify:track:soloC',
                        'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
                        'album' => ['name' => 'C', 'images' => []],
                    ],
                    [
                        'id' => 'soloD',
                        'name' => 'Solo D',
                        'uri' => 'spotify:track:soloD',
                        'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
                        'album' => ['name' => 'D', 'images' => []],
                    ],
                    [
                        'id' => 'soloE',
                        'name' => 'Solo E',
                        'uri' => 'spotify:track:soloE',
                        'artists' => [['id' => 'artistSolo1', 'name' => 'Solo Act']],
                        'album' => ['name' => 'E', 'images' => []],
                    ],
                ],
            ]),
            'api.reccobeats.com/*' => Http::response(['content' => []]),
        ]);

        $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedOnlyPrimary1&limit=5')
            ->assertOk()
            ->assertJsonCount(5, 'items');
    }

    public function test_similar_recommendations_drop_out_of_neighborhood_reccobeats_fill(): void
    {
        \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedGate1',
            'name' => 'Local Song',
            'uri' => 'spotify:track:seedGate1',
            'artists' => [['id' => 'artistGate1', 'name' => 'Local Act']],
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistGate1/related-artists' => Http::response([
                'artists' => [],
            ]),
            'api.spotify.com/v1/artists/artistGate1/top-tracks*' => Http::response([
                'tracks' => [],
            ]),
            'api.reccobeats.com/v1/track/recommendation*' => Http::response([
                'content' => [[
                    'id' => 'foreign-uuid',
                    'trackTitle' => 'Foreign Hit',
                    'href' => 'https://open.spotify.com/track/foreignTrack1',
                    'artists' => [[
                        'name' => 'Foreign Act',
                        'href' => 'https://open.spotify.com/artist/artistForeign1',
                    ]],
                ]],
            ]),
            'api.reccobeats.com/v1/audio-features*' => Http::response(['content' => []]),
        ]);

        $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedGate1&limit=5')
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_similar_recommendations_ban_skipped_artists_from_session(): void
    {
        \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedBan1',
            'name' => 'Current',
            'uri' => 'spotify:track:seedBan1',
            'artists' => [['id' => 'artistBanPrimary', 'name' => 'Primary']],
        ]);

        $skipped = \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'skippedBan1',
            'name' => 'Skipped Related',
            'uri' => 'spotify:track:skippedBan1',
            'artists' => [['id' => 'artistRelatedBan', 'name' => 'Related Skip']],
        ]);
        SpotifyListenSession::query()->create([
            'user_id' => $this->user->id,
            'spotify_track_id' => $skipped->id,
            'spotify_id' => 'skippedBan1',
            'started_at' => now()->subMinutes(5),
            'last_progress_ms' => 4_000,
            'max_progress_ms' => 4_000,
            'duration_ms' => 200_000,
            'status' => SpotifyListenSession::STATUS_CLOSED,
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistBanPrimary/related-artists' => Http::response([
                'artists' => [
                    ['id' => 'artistRelatedBan', 'name' => 'Related Skip'],
                    ['id' => 'artistRelatedOk', 'name' => 'Related Ok'],
                ],
            ]),
            'api.spotify.com/v1/artists/artistBanPrimary/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'primaryTopOk',
                    'name' => 'Primary Top',
                    'uri' => 'spotify:track:primaryTopOk',
                    'artists' => [['id' => 'artistBanPrimary', 'name' => 'Primary']],
                    'album' => ['name' => 'A', 'images' => []],
                ]],
            ]),
            'api.spotify.com/v1/artists/artistRelatedBan/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'relatedBannedTop',
                    'name' => 'Should Ban',
                    'uri' => 'spotify:track:relatedBannedTop',
                    'artists' => [['id' => 'artistRelatedBan', 'name' => 'Related Skip']],
                    'album' => ['name' => 'B', 'images' => []],
                ]],
            ]),
            'api.spotify.com/v1/artists/artistRelatedOk/top-tracks*' => Http::response([
                'tracks' => [[
                    'id' => 'relatedOkTop',
                    'name' => 'Keep Me',
                    'uri' => 'spotify:track:relatedOkTop',
                    'artists' => [['id' => 'artistRelatedOk', 'name' => 'Related Ok']],
                    'album' => ['name' => 'C', 'images' => []],
                ]],
            ]),
            'api.reccobeats.com/*' => Http::response(['content' => []]),
        ]);

        $response = $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedBan1&limit=10')
            ->assertOk();

        $ids = collect($response->json('items'))->pluck('track.id')->all();
        $this->assertContains('primaryTopOk', $ids);
        $this->assertContains('relatedOkTop', $ids);
        $this->assertNotContains('relatedBannedTop', $ids);
        $this->assertNotContains('skippedBan1', $ids);
    }

    public function test_similar_recommendations_prefer_closer_acoustic_fit(): void
    {
        $seed = \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedAcoustic1',
            'name' => 'Calm Anchor',
            'uri' => 'spotify:track:seedAcoustic1',
            'artists' => [['id' => 'artistAcoustic1', 'name' => 'Calm Act']],
        ]);
        TrackAudioFeatures::query()->create([
            'spotify_track_id' => $seed->id,
            'provider' => 'reccobeats',
            'provider_track_id' => 'uuid-acoustic-seed',
            'energy' => 0.30,
            'danceability' => 0.35,
            'valence' => 0.40,
            'tempo' => 90,
            'fetched_at' => now(),
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistAcoustic1/related-artists' => Http::response([
                'artists' => [],
            ]),
            'api.spotify.com/v1/artists/artistAcoustic1/top-tracks*' => Http::response([
                'tracks' => [
                    [
                        'id' => 'closeTrack1',
                        'name' => 'Close Fit',
                        'uri' => 'spotify:track:closeTrack1',
                        'artists' => [['id' => 'artistAcoustic1', 'name' => 'Calm Act']],
                        'album' => ['name' => 'A', 'images' => []],
                    ],
                    [
                        'id' => 'farTrack1',
                        'name' => 'Far Fit',
                        'uri' => 'spotify:track:farTrack1',
                        'artists' => [['id' => 'artistAcoustic1', 'name' => 'Calm Act']],
                        'album' => ['name' => 'B', 'images' => []],
                    ],
                ],
            ]),
            'api.reccobeats.com/v1/audio-features*' => Http::response([
                'content' => [
                    [
                        'id' => 'uuid-close',
                        'href' => 'https://open.spotify.com/track/closeTrack1',
                        'energy' => 0.32,
                        'danceability' => 0.34,
                        'valence' => 0.41,
                        'tempo' => 92,
                        'acousticness' => 0.5,
                        'instrumentalness' => 0.1,
                        'speechiness' => 0.05,
                    ],
                    [
                        'id' => 'uuid-far',
                        'href' => 'https://open.spotify.com/track/farTrack1',
                        'energy' => 0.95,
                        'danceability' => 0.90,
                        'valence' => 0.85,
                        'tempo' => 170,
                        'acousticness' => 0.05,
                        'instrumentalness' => 0.0,
                        'speechiness' => 0.2,
                    ],
                ],
            ]),
            'api.reccobeats.com/v1/track/recommendation*' => Http::response(['content' => []]),
        ]);

        $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedAcoustic1&limit=2')
            ->assertOk()
            ->assertJsonPath('items.0.track.id', 'closeTrack1');
    }

    public function test_similar_recommendations_return_empty_on_spotify_rate_limit(): void
    {
        \App\Models\Spotify\SpotifyTrack::query()->create([
            'spotify_id' => 'seedRate1',
            'name' => 'Rate Song',
            'uri' => 'spotify:track:seedRate1',
            'artists' => [['id' => 'artistRate1', 'name' => 'Rate Act']],
        ]);

        Http::fake([
            'api.spotify.com/v1/me' => Http::response(['country' => 'ZA']),
            'api.spotify.com/v1/artists/artistRate1/related-artists' => Http::response([
                'error' => ['message' => 'Rate limited'],
            ], 429),
            'api.spotify.com/v1/artists/artistRate1/top-tracks*' => Http::response([
                'error' => ['message' => 'Rate limited'],
            ], 429),
            'api.reccobeats.com/*' => Http::response(['content' => []]),
        ]);

        $this->getJson('/api/v1/spotify/recommendations/similar?seed=seedRate1&limit=5')
            ->assertOk()
            ->assertJsonPath('items', []);
    }

    public function test_listening_settings_round_trip(): void
    {
        $this->getJson('/api/v1/spotify/listening/settings')
            ->assertOk()
            ->assertJsonPath('auto_queue_enabled', false);

        $this->putJson('/api/v1/spotify/listening/settings', [
            'auto_queue_enabled' => true,
            'auto_queue_min_upcoming' => 4,
            'auto_queue_batch' => 3,
        ])
            ->assertOk()
            ->assertJsonPath('auto_queue_enabled', true)
            ->assertJsonPath('auto_queue_min_upcoming', 4)
            ->assertJsonPath('auto_queue_batch', 3);
    }

    public function test_listening_profile_averages_weighted_samples(): void
    {
        Http::fake([
            'api.reccobeats.com/v1/audio-features*' => Http::response([
                'content' => [[
                    'id' => 'uuid-profile',
                    'href' => 'https://open.spotify.com/track/profileTrack',
                    'energy' => 0.9,
                    'danceability' => 0.4,
                    'valence' => 0.2,
                    'tempo' => 140,
                    'acousticness' => 0.1,
                    'instrumentalness' => 0,
                    'key' => 2,
                    'liveness' => 0.1,
                    'loudness' => -5,
                    'mode' => 1,
                    'speechiness' => 0.04,
                ]],
            ]),
        ]);

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'profileTrack',
            'progress_ms' => 120_000,
            'duration_ms' => 200_000,
            'name' => 'Profile Song',
        ])->assertOk();

        $this->postJson('/api/v1/spotify/listening/heartbeat', [
            'spotify_id' => 'nextTrack',
            'progress_ms' => 0,
            'duration_ms' => 200_000,
            'name' => 'Next',
        ])->assertOk();

        $this->getJson('/api/v1/spotify/listening/profile')
            ->assertOk()
            ->assertJsonPath('windows.all.averages.energy', 0.9)
            ->assertJsonPath('windows.all.sample_count', 1);

        $this->getJson('/api/v1/spotify/taste')
            ->assertOk()
            ->assertJsonStructure(['audio_metrics' => ['7d', '30d']]);
    }
}
