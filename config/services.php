<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'redirect' => env('SPOTIFY_REDIRECT_URI', 'http://127.0.0.1:80/spotify/callback'),
        'frontend_redirect' => env('SPOTIFY_FRONTEND_REDIRECT', 'http://nexus.test/spotify'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'app_id' => env('GITHUB_APP_ID'),
        'private_key' => env('GITHUB_PRIVATE_KEY'),
        'redirect' => env('GITHUB_REDIRECT_URI', 'http://127.0.0.1:80/github/callback'),
        'frontend_redirect' => env('GITHUB_FRONTEND_REDIRECT', 'http://nexus.test/github'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream provider rate gates (protect remote APIs)
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'sportsdb' => [
            'max_attempts' => (int) env('SPORTSDB_RATE_MAX', 20),
            'decay_seconds' => (int) env('SPORTSDB_RATE_DECAY', 60),
            // Never park a queue worker sleeping on SportsDB — jobs release instead.
            'max_wait_seconds' => (int) env('SPORTSDB_RATE_MAX_WAIT', 0),
        ],
        'spotify' => [
            'max_attempts' => (int) env('SPOTIFY_RATE_MAX', 90),
            'decay_seconds' => (int) env('SPOTIFY_RATE_DECAY', 60),
        ],
        'github' => [
            'max_attempts' => (int) env('GITHUB_RATE_MAX', 50),
            'decay_seconds' => (int) env('GITHUB_RATE_DECAY', 60),
        ],
    ],

    'sportsdb' => [
        'api_key' => env('SPORTSDB_API_KEY', '123'),
        'base_url' => env('SPORTSDB_BASE_URL', 'https://www.thesportsdb.com/api/v1/json'),
        'leagues' => [
            'football' => [
                ['id' => 4328, 'name' => 'Premier League'],
                ['id' => 4331, 'name' => 'Bundesliga'],
                ['id' => 4334, 'name' => 'Ligue 1'],
                ['id' => 4335, 'name' => 'La Liga'],
                ['id' => 4429, 'name' => 'FIFA World Cup'],
            ],
            'tennis' => [
                ['id' => 4464, 'name' => 'ATP World Tour'],
                ['id' => 4517, 'name' => 'WTA Tour'],
            ],
            'rugby' => [
                ['id' => 4714, 'name' => 'Six Nations Championship'],
                ['id' => 4446, 'name' => 'United Rugby Championship'],
                ['id' => 4986, 'name' => 'Rugby Championship'],
                ['id' => 4574, 'name' => 'Rugby World Cup'],
                ['id' => 5852, 'name' => 'Nations Championship'],
                ['id' => 5479, 'name' => 'Rugby Union International Friendlies'],
            ],
            'golf' => [
                ['id' => 4425, 'name' => 'PGA Tour'],
                ['id' => 4426, 'name' => 'European Tour'],
            ],
            'darts' => [
                ['id' => 4554, 'name' => 'PDC Darts'],
            ],
            'field-hockey' => [
                ['id' => 4558, 'name' => 'Mens FIH Pro League'],
                ['id' => 4559, 'name' => 'Euro Hockey League'],
                ['id' => 4560, 'name' => 'Hockey World Cup'],
                ['id' => 4585, 'name' => 'Olympics Field Hockey'],
            ],
        ],
        'major_keywords' => [
            'tennis' => [
                'Australian Open',
                'French Open',
                'Roland Garros',
                'Wimbledon',
                'US Open',
                'U.S. Open',
            ],
            'golf' => [
                'Masters Tournament',
                'Masters',
                'PGA Championship',
                'U.S. Open',
                'US Open',
                'The Open Championship',
                'Open Championship',
                'British Open',
            ],
        ],
        'sport_api_names' => [
            'football' => 'Soccer',
            'tennis' => 'Tennis',
            'rugby' => 'Rugby',
            'golf' => 'Golf',
            'darts' => 'Darts',
            'field-hockey' => 'Field Hockey',
        ],
        /*
         * Sync jobs stay micro-batched — free SportsDB + small Docker workers
         * OOM when a single job holds large payloads or waits on rate limits.
         */
        'sync' => [
            'fixture_chunk_size' => (int) env('SPORTSDB_FIXTURE_CHUNK', 1),
            'fixture_chain_delay_seconds' => (int) env('SPORTSDB_FIXTURE_CHAIN_DELAY', 1),
            'rate_limit_release_seconds' => (int) env('SPORTSDB_RATE_RELEASE', 45),
            'queue' => env('SPORTSDB_SYNC_QUEUE', 'default'),
            'max_next_events' => (int) env('SPORTSDB_MAX_NEXT_EVENTS', 10),
            'max_past_events' => (int) env('SPORTSDB_MAX_PAST_EVENTS', 10),
            'max_day_events' => (int) env('SPORTSDB_MAX_DAY_EVENTS', 40),
            'result_text_max' => (int) env('SPORTSDB_RESULT_TEXT_MAX', 1200),
            'day_lookback' => [
                'football' => 1,
                'rugby' => 1,
                'golf' => 2,
                'tennis' => 2,
                'darts' => 1,
                'field-hockey' => 1,
            ],
        ],
    ],

];
