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
| `DB_HOST` | `mysql` |
| `DB_DATABASE` | `nexus_db` |
| `DB_USERNAME` | `root` |
| `DB_PASSWORD` | `root` |
| `QUEUE_CONNECTION` | `database` |

Generate an app key if missing:

```bash
php artisan key:generate
```

## Adding a New Module

Before scaffolding: confirm the milestone is active and a short kickoff note exists (see [VISION.md](VISION.md) / [ROADMAP.md](ROADMAP.md)). Then:

1. Create migrations: `php artisan make:migration create_{module}_{table}_table`
2. Create models: `php artisan make:model Models/{Module}/{Entity}`
3. Create service class in `app/Services/{Module}/`
4. Create API controller: `php artisan make:controller Api/{Module}/{Entity}Controller`
5. Register routes in `routes/api.php` under a prefix (e.g. `Route::prefix('spotify')`)
6. Add queued jobs for any external sync: `php artisan make:job {Module}/Sync{Resource}`

## API Testing

```bash
# Health check
curl http://api.nexus.test/up

# Authenticated route (requires Sanctum token)
curl -H "Authorization: Bearer {token}" http://api.nexus.test/api/user
```

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
