# Docker + Octane (FrankenPHP) for Render

This folder is published by **Laravel Scale** (`provydon/laravel-scale`). It’s the Docker and process layout that sets up most Laravel apps to scale (web + worker-scheduler, stateless). It contains:

- **Dockerfile** – Multi-stage build: Node frontend, then PHP (FrankenPHP) + Supervisor
- **docker-entrypoint.sh** – Builds `.env` from `.env.example` + Render env, runs migrations (web), starts Supervisor
- **supervisord-web.conf** – Octane (FrankenPHP) on port 8000
- **supervisord-worker.conf** – `queue:work` + `schedule:work`
- **php.ini** – OPcache and upload limits

## PHP version

The Dockerfile uses the **dunglas/frankenphp** base image with **no tag**, so the image uses whatever the image’s **`latest`** tag is. The FrankenPHP image provides variants for **PHP 8.2, 8.3, 8.4, and 8.5**; `latest` typically tracks the newest of these and can change over time.

The app’s `composer.json` requires **PHP ^8.2**, so any of these versions is supported.

**To pin a specific PHP version**, use a tagged base image in the Dockerfile, for example:

```dockerfile
FROM dunglas/frankenphp:1-php8.3-bookworm AS php-base
```

Check [Docker Hub – dunglas/frankenphp tags](https://hub.docker.com/r/dunglas/frankenphp/tags) for the exact tag pattern and available versions (e.g. `1-php8.2-bookworm`, `1-php8.4-bookworm`).

**To see which PHP version is in your built image**, run:

```bash
docker run --rm dunglas/frankenphp php -v
```

(or use your built image name instead of `dunglas/frankenphp`).

## Database

The Dockerfile includes `pdo_pgsql` and `pdo_sqlite` by default. Use SQLite (`DB_CONNECTION=sqlite`) for quick local Docker testing without a database server. For production, use PostgreSQL—most autoscaling platforms (Render, Fly.io, Railway, etc.) offer it as a managed service. To use MySQL instead, replace `pdo_pgsql` with `pdo_mysql` (and `mysqli` if needed) in the `install-php-extensions` block.

## Deployment types (Render)

- **Web**: build with `DEPLOYMENT_TYPE=web` (default). Starts Octane + HTTP on 8000.
- **Worker**: build with `DEPLOYMENT_TYPE=worker`. Skips frontend in image, runs queue worker + scheduler only.

Use the same image with different `DEPLOYMENT_TYPE` env var, or build two images (web + worker) for smaller worker image.

**Why both?** A dedicated worker-scheduler service avoids scheduler race conditions (only one process runs cron tasks) and keeps web containers from handling queue and scheduled work, so they stay fast for HTTP requests.

---

## Octane in production

The Dockerfile runs `composer install --no-dev`. `scale:install` ensures **`laravel/octane` is in `require`** in your `composer.json` so the image gets Octane. If you installed Octane yourself, keep it in `require` (not `require-dev`).

## Making your app stateless (required for scaling)

Render instances are ephemeral and can scale to N copies. **Session, cache, and file storage must not rely on local disk.**

### 1. Session → database (or Redis)

- **Driver**: `SESSION_DRIVER=database` (or `redis`).
- **Table**: Laravel’s default migration creates `sessions`. Ensure migrations are run on deploy (entrypoint does this for web).
- **Config**: `config/session.php` – `connection` and `table` stay default unless you need a dedicated DB.

```env
SESSION_DRIVER=database
# SESSION_CONNECTION=  # optional, default DB
# SESSION_TABLE=sessions
```

### 2. Cache → database or Redis

- **Driver**: `CACHE_STORE=database` or `redis`.
- **Tables**: Default migration creates `cache` and `cache_locks`. Run migrations on web deploy.
- Prefer **Redis** for performance at scale; **database** is fine and keeps dependencies simple.
- **Redis/key-value cache**: If you use Redis, add the PHP extension first—in the Dockerfile’s `install-php-extensions` block, add `redis \` on its own line before the other extensions. Then deploy a Redis or key-value cache service (e.g. Render Redis, Upstash, or your platform’s managed Redis). Point the web service at it via env configs—set `CACHE_STORE=redis` (and optionally `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`) plus `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, etc. No code changes required.

```env
CACHE_STORE=database
# Or: CACHE_STORE=redis and configure REDIS_* in .env
```

### 3. Files (uploads, exports) → external storage

- **Do not** store user uploads or app-generated files on local disk; it’s not shared across instances.
- Use **S3** (or compatible): set `FILESYSTEM_DISK=s3` and configure `AWS_*` in Render env.
- Use the `s3` disk for public and private files; use `Storage::disk('s3')` in code.
- Keep `storage:link` for compatibility (e.g. public assets); actual uploads should go to S3.

```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=...
AWS_BUCKET=...
# Optional: AWS_URL for CDN/public URL
```

### 4. Queue (workers)

- Use **database** or **redis** for the queue so workers on a separate service can process jobs.
- **Web** service runs Octane only; **Worker** service runs `queue:work` + `schedule:work` (supervisord-worker.conf).

```env
QUEUE_CONNECTION=database
# Or: QUEUE_CONNECTION=redis
```

### 5. Broadcasting (optional)

If your app uses Laravel Echo and real-time events (Pusher, Reverb, Ably, etc.), add the broadcaster env vars to both Web and Worker services. Broadcast jobs are queued, so the worker will process them. Example for Pusher:

```env
BROADCAST_CONNECTION=pusher
PUSHER_APP_ID=...
PUSHER_APP_KEY=...
PUSHER_APP_SECRET=...
PUSHER_APP_CLUSTER=...
```

---

## Checklist

| Concern        | Use in production (stateless) |
|----------------|--------------------------------|
| Sessions       | `SESSION_DRIVER=database` or `redis` |
| Cache          | `CACHE_STORE=database` or `redis` |
| Uploads/files  | `FILESYSTEM_DISK=s3` (or other external disk) |
| Queue          | `QUEUE_CONNECTION=database` or `redis` |
| Broadcasting   | Optional: `BROADCAST_CONNECTION=pusher` (or reverb/ably) + driver-specific env vars |
| Logs           | Optional: ship `storage/logs` to external service; container logs go to Render stdout |

---

## Wayfinder (Laravel Wayfinder)

If you use **Laravel Wayfinder**, `scale:install` automatically removes the Wayfinder Vite plugin from `vite.config.*` (so the Docker frontend stage doesn't run PHP) and ensures `resources/js/routes/`, `resources/js/actions/`, and `resources/js/wayfinder/` are committed. Run **`php artisan wayfinder:generate`** locally after install, then commit the generated files. The image will then build without Wayfinder errors.

## If `npm run build` fails (other causes)

If the frontend build still fails (e.g. missing env, other plugins), you can skip it and deploy without assets:

- **On Render**: In your Web Service → **Environment** → add a **Docker Build Argument**: name `SKIP_FRONTEND`, value `1`. The image will build with an empty `public/build` (no JS/CSS).
- **Local**: `docker build -f docker/Dockerfile --build-arg SKIP_FRONTEND=1 --build-arg DEPLOYMENT_TYPE=web -t app:latest .`

## Backend-only / API-only apps (no frontend)

If your app has **no Node frontend** (Vite, Blade with assets, etc.)—e.g. a pure API or backend service—you can remove the frontend parts from the Dockerfile to speed up builds and shrink the image:

1. Remove the entire **frontend stage** (between the `# --- BEGIN FRONTEND` and `# --- END FRONTEND ---` comments): the `FROM node:20 AS frontend` block through `RUN if [ "$SKIP_FRONTEND" = "1" ]...`.
2. Remove the **frontend copy block** (between `# --- BEGIN FRONTEND COPY` and `# --- END FRONTEND COPY ---`): the `COPY --from=frontend` line and the `RUN if [ "$DEPLOYMENT_TYPE" != "worker" ]...` block.

The Dockerfile comments mark these sections. After removal, the image will build without Node and skip copying any frontend assets.

## Build and run

- **Web**:  
  `docker build -f docker/Dockerfile --build-arg DEPLOYMENT_TYPE=web -t app:latest .`  
  Start with port **8000** and `DEPLOYMENT_TYPE=web`.
- **Worker**:  
  Same image with `DEPLOYMENT_TYPE=worker`, or build with `--build-arg DEPLOYMENT_TYPE=worker` for a slimmer image (no frontend).  
  No port needed; set start command to the image’s entrypoint (default).

### Deploying on Render.com

1. **Web Service**: New → Web Service → connect repo → Environment: **Docker**. Set **Dockerfile Path** to **`docker/Dockerfile`** (required; in Advanced if not visible). **Port**: **8000**. Env: `DEPLOYMENT_TYPE=web`, `APP_KEY`, DB_*, and stateless vars (`SESSION_DRIVER=database`, `CACHE_STORE=database`, etc.). Leave **Start Command** empty so the image entrypoint runs.
2. **Worker**: New → Background Worker → same repo, Docker. Env: `DEPLOYMENT_TYPE=worker` and same DB/Redis/APP_KEY as web. Optional: build with `--build-arg DEPLOYMENT_TYPE=worker` for a smaller image.
3. Add a **PostgreSQL** instance in Render and attach its URL to both services. Prefer a **custom domain** (e.g. `app.yourdomain.com`) over the platform default (`*.onrender.com`) and set **`APP_URL`** to the exact URL your app’s DNS points to—with a load balancer, `APP_URL` must match the public URL or links, redirects, and assets can break.

See the package **README.md** for full step-by-step Render deployment instructions.
