# Nexus API

Backend for **Nexus Hub** — a Laravel 11 REST API for a personal life hub: integrations, collections, media, and sports context. API-first and stateless so the Vue dashboard and a future mobile client share the same endpoints.

**Local URL:** [http://api.nexus.test](http://api.nexus.test)  
**Health check:** [http://api.nexus.test/up](http://api.nexus.test/up)

## Stack

- PHP 8.3
- Laravel 11
- Laravel Sanctum (API token auth)
- MySQL 8 (`nexus_db`)
- Database-backed queues and cache

## Vision (short)

Nexus consolidates personal data into modular domains — Spotify, GitHub, wine cellar, book library, kitchen, media vaults, optional social integrations, and sports/F1 — with a mobile app later for photo-driven collection intake. See [docs/VISION.md](docs/VISION.md) and [docs/ROADMAP.md](docs/ROADMAP.md).

**Immediate next focus when building features:** finish authentication, then tackle the next milestone (likely Spotify) with a short kickoff spec.

## Quick Start

All Artisan and Composer commands run inside the Laradock workspace container:

```bash
# From the host
docker exec -it laradock-workspace-1 bash
cd /var/www/nexus-api

composer install
cp .env.example .env   # skip if .env already exists
php artisan key:generate
php artisan migrate
```

Environment is pre-configured for the Laradock MySQL service:

```dotenv
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=nexus_db
DB_USERNAME=root
DB_PASSWORD=root
```

## API Design Principles

- **RESTful and stateless** — no session-dependent endpoints under `/api/*`.
- **Sanctum token auth** — bearer tokens for the web SPA and future mobile app.
- **JSON-first** — all `/api/*` routes return JSON (configured in `bootstrap/app.php`).
- **Background sync** — external ingestion (Spotify, GitHub, sports, …) via queued jobs, not inline in request handlers.
- **Module namespaces** — each pillar (Spotify, Cellar, Library, …) owns models, migrations, controllers, jobs, and services.

## Project Structure (planned)

```
app/
├── Http/Controllers/Api/     # REST controllers grouped by module
├── Models/                   # Eloquent models
├── Services/                 # External API clients & business logic
├── Jobs/                     # Async sync and ingestion jobs
└── ...
routes/
└── api.php                   # All API routes (module-prefixed)
database/migrations/          # Schema per module
```

## Documentation

| Document | Description |
|----------|-------------|
| [docs/VISION.md](docs/VISION.md) | Product vision and pillars |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Milestone roadmap (high-level) |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System design, modules, data flow |
| [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) | Local dev workflow, Artisan, queues |

## Related

- Web client: [`../nexus-web/`](../nexus-web/) → [http://nexus.test](http://nexus.test)
- Monorepo overview: [`../README.md`](../README.md)
