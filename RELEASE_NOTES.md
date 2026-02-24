# Laravel Scale — Initial Release

Scale your Laravel app with one command: production Docker, Laravel Octane (FrankenPHP), and a stateless web + worker layout ready for Render, Fly.io, Railway, and other container platforms.

## Requirements

- **PHP** ^8.2  
- **Laravel** ^11.0 | ^12.0  
- **laravel/octane** ^2.13 (FrankenPHP)

## What’s included

### One-command install

- **`php artisan scale:install`** — Run once locally. Publishes Docker and config into your app; you commit and push. No `scale:install` in CI or on the platform.

### Docker

- **Single image, two modes** — Web (Octane) or worker (queue + scheduler) via `DEPLOYMENT_TYPE`.
- **Published `docker/`** — Dockerfile, entrypoint, `supervisord-web.conf`, `supervisord-worker.conf`, `php.ini`.
- **`.dockerignore`** — Keeps build context small and fast.
- **Port 8000** for the web service.

### Octane (FrankenPHP)

- **High-concurrency HTTP** — Octane with FrankenPHP server.
- Optional install via `scale:install` (skip with `--no-octane`).
- Moves `laravel/octane` into `require` so production Docker gets it with `composer install --no-dev`.

### Stateless by design

- **Session** — Database or Redis (`SESSION_DRIVER=database` or `redis`).
- **Cache** — Database or Redis (`CACHE_STORE=database` or `redis`).
- **Files** — S3 or other external disk (`FILESYSTEM_DISK=s3` + AWS_*).
- **Queue** — Database or Redis for the worker (`QUEUE_CONNECTION=database` or `redis`).
- **Scheduler** — Single dedicated worker runs `schedule:work` to avoid duplicate cron runs across instances.

### Platform guidance

- **Render.com** — Step-by-step for Web Service (Docker) and Background Worker; Dockerfile path `docker/Dockerfile`, port 8000, env vars, PostgreSQL, optional Redis, custom domains.
- **Proxy & HTTPS** — `trustProxies(at: '*')` and `statefulApi()` in `bootstrap/app.php` for correct URLs behind a reverse proxy.
- **docker/README.md** — Stateless checklist, PHP version selection (8.2–8.5), database options (PostgreSQL default, MySQL/SQLite), backend-only (no Node) variant.

### Install options

- `--no-octane` — Publish files only; don’t run Octane install.
- `--no-dockerignore` — Don’t overwrite `.dockerignore`.
- `--no-gitignore` — Don’t update `.gitignore`.
- `--no-wayfinder` — Skip Laravel Wayfinder Vite and .gitignore adjustments.

### Laravel Wayfinder

- If detected: removes Wayfinder from Vite config (so `npm run build` in Docker doesn’t need PHP), updates `.gitignore` for generated routes/actions/wayfinder, with instructions to run `wayfinder:generate` locally and commit before deploy.

## Install

```bash
composer require laravel-scale/laravel-scale --dev
php artisan scale:install
```

Then commit `docker/`, `.dockerignore`, and `config/octane.php` (if Octane was installed), and deploy.

## License

MIT.
