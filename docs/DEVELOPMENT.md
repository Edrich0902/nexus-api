# Nexus API — Development Guide

## Prerequisites

- Docker Desktop running
- Laradock stack up (`nginx`, `mysql`, `php-fpm`, `workspace`)
- Host entries: `127.0.0.1 api.nexus.test nexus.test`

## Container Access

```bash
docker exec -it laradock-workspace-1 bash
cd /var/www/nexus-api
```

The host `nexus-hub/` directory is mounted at `/var/www/` inside containers.

## Common Commands

Run these inside the workspace container unless noted.

```bash
# Dependencies
composer install

# Database
php artisan migrate
php artisan migrate:status
php artisan db:seed

# Development server (optional — nginx handles api.nexus.test in normal use)
php artisan serve --host=0.0.0.0 --port=8000

# Queue worker (required once background jobs exist)
php artisan queue:work --tries=3

# Code style
./vendor/bin/pint

# Tests
php artisan test
```

## Telescope (local debugging)

Telescope records requests, exceptions, queries, and jobs so intermittent issues (e.g. cache / rate-limit errors on Spotify player polls) can be inspected after the fact.

```bash
# Inside workspace container
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

- UI: [http://api.nexus.test/telescope](http://api.nexus.test/telescope) (open in `local`; non-local uses the `viewTelescope` gate)
- Toggle: `TELESCOPE_ENABLED=true|false` in `.env`
- Prefer `CACHE_STORE=file` (or redis) — database cache can deadlock under concurrent RateLimiter hits from player polling

## Queue Worker

The API uses the `database` queue driver. After adding jobs, keep a worker running:

```bash
# Inside workspace container
php artisan queue:work
```

For local development, `composer dev` (defined in `composer.json`) runs server, queue, logs, and Vite concurrently — useful when developing outside Docker.

## Nginx Routing

Configured in `laradock/nginx/sites/api.nexus.test.conf`:

- Document root: `/var/www/nexus-api/public`
- PHP handled by `php-fpm:9000`

After changing nginx config:

```bash
cd laradock
docker compose restart nginx
```

## Environment

Copy `.env.example` to `.env` and set:

| Variable | Local value |
|----------|-------------|
| `APP_URL` | `http://api.nexus.test` |
| `APP_FORCE_HTTPS` | `false` (set `true` or use `staging`/`production` env to force HTTPS) |
| `DB_HOST` | `mysql` |
| `DB_DATABASE` | `nexus_db` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | `root` |
| `QUEUE_CONNECTION` | `database` |
| `CORS_ALLOWED_ORIGINS` | `http://nexus.test,http://localhost:5173,https://nexus.barforge.co.za` |
| `SPOTIFY_CLIENT_ID` / `SPOTIFY_CLIENT_SECRET` | From Spotify Developer Dashboard |
| `SPOTIFY_REDIRECT_URI` | `http://127.0.0.1:80/spotify/callback` (must match dashboard exactly; Spotify requires an explicit loopback port) |
| `SPOTIFY_FRONTEND_REDIRECT` | `http://nexus.test/spotify` |

Generate an app key if missing:

```bash
php artisan key:generate
```

## Queue Worker

Spotify sync jobs use the `database` queue. Keep a worker running when testing connect/sync:

```bash
# Inside workspace container
php artisan queue:work --tries=3
```

Scheduler (recent every 15m, tops daily, playlists every 3h) needs:

```bash
php artisan schedule:work
# or cron: * * * * * php /var/www/nexus-api/artisan schedule:run
```

Manual sync for all connected users:

```bash
php artisan spotify:sync --type=recent
php artisan spotify:sync --type=tops
php artisan spotify:sync --type=playlists
```

## Spotify OAuth (local)

1. Dashboard redirect URI: `http://127.0.0.1:80/spotify/callback` (not `api.nexus.test` — Spotify requires HTTPS for custom hosts; loopback must include an explicit port).
2. Confirm loopback hits the API: `curl -sI http://127.0.0.1/` → Laravel / Nexus_Api.
3. Authenticated `GET /api/v1/spotify/connect` → open `url` → Spotify redirects to loopback callback → browser sent to `SPOTIFY_FRONTEND_REDIRECT`.
4. After scope additions (e.g. `user-follow-read`), status returns `needs_reauth` / `missing_scopes` until the user reconnects once.

## Adding a New Module

Before scaffolding: confirm the milestone is active and a short kickoff note exists (see [VISION.md](VISION.md) / [ROADMAP.md](ROADMAP.md)). Then:

1. Create migrations: `php artisan make:migration create_{module}_{table}_table`
2. Create models: `php artisan make:model Models/{Module}/{Entity}`
3. For OAuth/API integrations: extend `app/Integrations/BaseIntegration` (see `SpotifyIntegration`)
4. Create service class in `app/Services/{Module}/`
5. Create API controller under `App\Http\Controllers\Api\V1\...`
6. Register routes in `routes/api/v1/{module}.php` and `require` from `routes/api/v1/api.php`
7. Add queued jobs for external sync under `app/Jobs/{Module}/`

## API Testing

```bash
# Health check
curl http://api.nexus.test/up

# Login (returns bearer token, expires_at, user) — 4 hour token
curl -X POST http://api.nexus.test/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"edrich@nexus.test","password":"password"}'

# Login with remember me — 24 hour token
curl -X POST http://api.nexus.test/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"edrich@nexus.test","password":"password","remember":true}'

# Current user (requires Sanctum token)
curl -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  http://api.nexus.test/api/v1/auth/me

# Update profile name
curl -X PATCH http://api.nexus.test/api/v1/auth/profile \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name":"Edrich"}'

# Refresh / rotate token (must still be valid)
curl -X POST http://api.nexus.test/api/v1/auth/refresh \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# List active sessions / devices
curl -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  http://api.nexus.test/api/v1/auth/sessions

# Revoke one session by id
curl -X DELETE http://api.nexus.test/api/v1/auth/sessions/{id} \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Logout current token
curl -X POST http://api.nexus.test/api/v1/auth/logout \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

Seed a local user first (`php artisan db:seed` inside the workspace container) if the database is empty.
## Logs

- Laravel log: `storage/logs/laravel.log`
- Nginx access: `laradock/logs/nginx/nexus_api_access.log`
- Nginx error: inside nginx container at `/var/log/nginx/nexus_api_error.log`

Tail logs from the host:

```bash
tail -f nexus-api/storage/logs/laravel.log
```

## Git

This repo is independent from `nexus-web`:

```bash
git remote -v
# origin  git@github.com:Edrich0902/nexus-api.git
```

Commit and push from `nexus-api/` only — not from the monorepo root.
