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
| Spotify | `/api/v1/spotify/*` | Listening sync & analytics |
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

Third-party modules (Spotify, GitHub, sports, optional social) typically share a pattern: OAuth or API keys → encrypted credentials → queued sync → normalized tables → read APIs for clients.

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

## Environment variables (planned, per milestone)

```dotenv
# Spotify
SPOTIFY_CLIENT_ID=
SPOTIFY_CLIENT_SECRET=
SPOTIFY_REDIRECT_URI=http://api.nexus.test/spotify/callback

# GitHub (when prioritized)
GITHUB_CLIENT_ID=
GITHUB_CLIENT_SECRET=

# Sports / F1 (when prioritized)
F1_API_KEY=
SPORTS_API_KEY=
```

Exact variable sets are defined when each integration is specified.
