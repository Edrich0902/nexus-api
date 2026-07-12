# Nexus API — Architecture

## Overview

Nexus API is the central data layer for **Nexus Hub**, a personal life hub. It persists owned data, ingests from external services where useful, and exposes RESTful JSON endpoints for:

1. **nexus-web** — desktop-first Vue SPA (`nexus.test`)
2. **Future mobile app** — same endpoints, photo-friendly payloads for collections

```
┌─────────────┐     HTTPS/JSON      ┌──────────────┐
│  nexus-web  │ ◄─────────────────► │  nexus-api   │
│  (Vue SPA)  │   Bearer (Sanctum)  │  (Laravel)   │
└─────────────┘                     └──────┬───────┘
                                           │
                    ┌──────────────────────┼──────────────────────┐
                    │                      │                      │
              ┌─────▼─────┐         ┌──────▼──────┐       ┌──────▼──────┐
              │   MySQL   │         │ Queue Worker │       │  External   │
              │  nexus_db │         │  (database)  │       │    APIs     │
              └───────────┘         └──────────────┘       └─────────────┘
                                                          Spotify, GitHub,
                                                          sports, …
```

Vision and sequencing: [VISION.md](VISION.md), [ROADMAP.md](ROADMAP.md).

## Module sketch

Each module is a vertical slice: migrations, models, services, jobs, and API routes. Details below are **directional** — finalized when that milestone is specified.

| Module | Prefix (planned) | Role |
|--------|------------------|------|
| Auth / users | `/api/v1/auth/*` | Sanctum login, tokens, current user |
| Spotify | `/api/v1/spotify/*` | Connect remote + listening sync & live player proxy |
| GitHub | `/api/v1/github/*` | Developer activity / context |
| Cellar | `/api/v1/cellar/*` | Wine collection |
| Library | `/api/v1/library/*` | Book collection |
| Kitchen | `/api/v1/kitchen/*` | Recipes |
| Media vaults | `/api/v1/media/*` (TBD) | Personal media libraries |
| Social | TBD | Optional (e.g. Instagram) |
| Sports / F1 | `/api/v1/sports/*`, `/api/v1/f1/*` | Schedules, standings, ticker |

### Route & code layout

URI versioning (`/api/v1/...`). Routes are modular:

- `routes/api.php` — loads version groups only
- `routes/api/v1/api.php` — requires module route files
- `routes/api/v1/{module}.php` — maps paths to controllers

HTTP flow: **Form Request → Controller → Service → API Resource**. Controllers stay thin; business logic lives in `app/Services/{Module}/`.

### Collections note (Cellar & Library)

CRUD on the web is the first surface. Schema and endpoints should leave room for **mobile photo / label intake** as a primary future path for adding bottles and books.

### Integrations note

Third-party modules (Spotify, GitHub, sports, optional social) share:

`BaseIntegration` (OAuth + token refresh + authenticated HTTP) → encrypted `integration_connections` → queued sync into module tables → read APIs; **live proxy** for realtime control surfaces (e.g. Spotify player).

Spotify specifically:

| Concern | Strategy |
|---------|----------|
| OAuth callback (local) | `http://127.0.0.1:80/spotify/callback` (Spotify requires loopback + explicit port for non-HTTPS) |
| OAuth callback (prod) | `https://<api-host>/spotify/callback` |
| Tokens | Encrypted on `integration_connections` |
| Player / devices / queue | Live proxy via `/api/v1/spotify/player*` (`spotify-player` 120/min) |
| Search | Live proxy `GET /api/v1/spotify/search` (`spotify-search` 30/min, limit ≤10) |
| Artist / album catalog | Live proxy + 10m cache (`spotify-catalog` 60/min); local fallback on 403 |
| Library browse | `GET /library/tracks|albums|artists` (`spotify-library` 40/min) |
| Recent / tops / playlists / taste | Synced to `spotify_*` tables; served from DB |
| Library + playlist mutations | Write-through to Spotify, then re-sync |

Integration code lives under `app/Integrations/` (`BaseIntegration`, `Spotify/SpotifyIntegration`, `Github/GithubIntegration`). Domain orchestration stays in `app/Services/Spotify/` and `app/Services/Github/`.

GitHub specifically:

| Concern | Strategy |
|---------|----------|
| OAuth callback (local) | `http://127.0.0.1:80/github/callback` (same loopback pattern as Spotify) |
| OAuth callback (prod) | `https://<api-host>/github/callback` |
| Auth type | GitHub App user access tokens (expiring + refresh) |
| Tokens | Encrypted on `integration_connections` (`provider=github`) |
| Profile | Live `GET /api/v1/github/me` |
| Repos | Synced to `github_repos` (includes `starred`); `GET /api/v1/github/repos`; star toggle live |
| PRs / diffs / commits / branches | Live proxy under `/api/v1/github/repos/{owner}/{repo}/…` (branch create/delete; reviews; GraphQL ready/draft) |
| Cross-repo PR inbox | Live `GET /api/v1/github/pulls` (search) |
| Home pulse | Live `GET /api/v1/github/pulse` (open + merged PRs, recent commits) |
| Global search | Live `GET /api/v1/github/search` (`repositories` / `issues` / `code`) |
| Create / merge PR | Write-through live proxy (`github-write` throttle); draft create + GraphQL ready/draft |

### Rate limiting (GitHub)

- `github-oauth-callback` 20/min IP
- `github-sync` 6/min
- `github-proxy` 60/min
- `github-search` 15/min
- `github-write` 20/min

## Shared API resource / response conventions

Auth and future modules return JSON via API Resources (`App\Http\Resources\Api\V1\...`) without a top-level `data` wrapper. Validation and auth errors use Laravel’s default JSON error shape (see Error handling below).

## Authentication

Laravel Sanctum bearer tokens:

- Web SPA and mobile use the same token flow
- Module routes under `/api/v1/*` require `auth:sanctum` unless explicitly public (e.g. login, OAuth callbacks)
- Auth endpoints: `POST /api/v1/auth/login`, `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout`, `POST /api/v1/auth/logout-all`, `GET /api/v1/auth/me`, `PATCH /api/v1/auth/profile`
- No public registration in v1 — users are seeded / created via artisan for this personal hub

### Token lifetimes

Sanctum global `expiration` stays `null`; each token sets its own `expires_at`:

| Login | Ability | Lifetime |
|-------|---------|----------|
| Default | `*` | 4 hours |
| `remember: true` | `*`, `remember` | 24 hours (hard ceiling) |

`POST /api/v1/auth/refresh` rotates the current token (revoke old → issue new) and preserves the remember-me lifetime class. Login and refresh responses include `expires_at`. Clients should refresh while the token is still valid (e.g. on app load); idle past expiry requires re-login.

### Profile

- `PATCH /api/v1/auth/profile` — update the authenticated user’s `name` (required string, max 255). Returns `UserResource`. Email change and avatar upload are deferred.

### Sessions / devices

- `GET /api/v1/auth/sessions` — list the user’s tokens with device metadata. Never returns token secrets.
- `DELETE /api/v1/auth/sessions/{id}` — revoke one device/session owned by the user.

Each session includes:

| Field | Meaning |
|-------|---------|
| `name` / `device.name` | Client-supplied label (`device_name` on login, default `nexus-web`) |
| `device.ip_address` | IP at login/refresh |
| `device.user_agent` | Browser/app user agent at login/refresh |
| `remember`, `expires_at`, `last_used_at`, `is_current` | Session state for the Vue devices UI |

### Rate limiting & CORS

- All `/api/*` routes: 60 requests / minute per user or IP
- `POST /api/v1/auth/login`: 5 requests / minute per IP + email
- Spotify API: `spotify-player` 120/min, `spotify-search` 30/min, `spotify-catalog` 60/min, `spotify-library` 40/min, `spotify-sync` 6/min
- Prefer `file` (or redis) for `CACHE_STORE` — the `database` cache driver can deadlock under concurrent RateLimiter writes (`spotify-player` polling)
- Spotify client: hub loads are staggered; HTTP layer backs off on `429` / `Retry-After`; player poll interval doubles on rate-limit up to 20s
- CORS allowlist via `CORS_ALLOWED_ORIGINS` (local `nexus.test`, Vite `localhost:5173`, production `https://nexus.barforge.co.za`)

### HTTPS

Outside local: `URL::forceScheme('https')` when `APP_ENV` is `production`/`staging`, or when `APP_FORCE_HTTPS=true`. Proxies are trusted so `X-Forwarded-Proto` works behind nginx.

### Deferred (needs email)

Change password and password reset are deferred until mail/notifications are integrated.

## Background processing

External API calls should not block HTTP responses.

```
HTTP Request → Controller → Dispatch Job → immediate response
                                │
                                ▼
                         Queue Worker → Service → External API → DB
```

Queue driver today: `database`. Redis later if Laradock Redis is enabled.

## Database conventions

- Plural, snake_case tables (`spotify_tracks`, `cellar_wines`)
- Module-oriented naming for clarity
- Timestamps on all tables; soft deletes where user data should be recoverable

## Error handling

`/api/*` returns JSON exceptions. Typical shape:

```json
{
  "message": "Human-readable summary",
  "errors": { "field": ["Validation message"] }
}
```

## Environment variables

```dotenv
# Spotify — local redirect MUST be loopback (Spotify blocks http://api.nexus.test)
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=http://127.0.0.1:80/spotify/callback
SPOTIFY_FRONTEND_REDIRECT=http://nexus.test/spotify

# GitHub App
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=
GITHUB_APP_ID=
GITHUB_PRIVATE_KEY=
GITHUB_REDIRECT_URI=http://127.0.0.1:80/github/callback
GITHUB_FRONTEND_REDIRECT=http://nexus.test/github

# Sports / F1 (when prioritized)
F1_API_KEY=
SPORTS_API_KEY=
```
