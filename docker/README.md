# Docker + Octane (FrankenPHP) for Render

This folder is published by **Laravel Scale** (`provydon/laravel-scale`). It’s the Docker and process layout that sets up most Laravel apps to scale (web + worker-scheduler, stateless). It contains:

- **Dockerfile** – Multi-stage build: Node frontend (Vite, Tailwind/PostCSS), then PHP (FrankenPHP) + Supervisor. Includes MySQL, PostgreSQL, and SQLite drivers by default.
- **docker-entrypoint.sh** – Builds `.env` from `.env.example` + platform env, runs migrations (web), starts Octane on `$PORT` (web) or Supervisor (worker)
- **supervisord-web.conf** – Reference config for Octane on a fixed port (web process is started by the entrypoint using `PORT` so Cloud Run, Render, etc. work)
- **supervisord-worker.conf** – `queue:work` + `schedule:work`
- **php.ini** – OPcache and upload limits

## BuildKit required

The Dockerfile uses `RUN --mount=type=cache,...` for Composer, which requires **Docker BuildKit**. When building locally or in CI, enable it:

- **Local:** `DOCKER_BUILDKIT=1 docker build -f docker/Dockerfile ...`
- **Cloud Build:** add `env: ['DOCKER_BUILDKIT=1']` to the step that runs `docker build`.

Without BuildKit, the build fails with “the --mount option requires BuildKit”.

## Port (Cloud Run, Render, etc.)

The **web** process listens on the **`PORT`** environment variable (default **8000** if unset). Cloud Run sets `PORT=8080`; Render and similar platforms set their own. The entrypoint starts Octane with `--port=$PORT` so the same image works everywhere. The worker deployment type still uses Supervisor and does not depend on PORT.

## PHP version

The Dockerfile uses the **dunglas/frankenphp** base image with **no tag**, so the image uses whatever the image’s **`latest`** tag is. The FrankenPHP image provides variants for **PHP 8.2, 8.3, 8.4, and 8.5**; `latest` typically tracks the newest of these and can change over time.

The app’s `composer.json` typically requires **PHP ^8.2**; **Laravel 13** requires **PHP ^8.3**, so pin the image (e.g. `1-php8.3-bookworm`) when you upgrade.

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

The Docker image includes **all three** Laravel database drivers by default:

| Driver       | PHP extension  | Use case                          |
|-------------|-----------------|-----------------------------------|
| MySQL       | `pdo_mysql`     | `DB_CONNECTION=mysql`             |
| PostgreSQL  | `pdo_pgsql`     | `DB_CONNECTION=pgsql`             |
| SQLite      | `pdo_sqlite`     | `DB_CONNECTION=sqlite`            |

No Dockerfile edits are required. Set `DB_CONNECTION` and the usual `DB_*` env vars (e.g. on Render) and it works. Use SQLite for quick local testing; use PostgreSQL or MySQL (managed on Render, Fly.io, Railway, etc.) for production.

---

> **Important: database must be reachable and the user must have access**
>
> Your app runs in containers. For migrations and the app to work, both of the following must be true.
>
> **1. The database must be reachable from your containers.**  
> If the DB is on another host (managed service or your own server), it must accept connections from the internet or your platform's network. The correct **port must be open**: **3306** for MySQL, **5432** for PostgreSQL. Managed DBs (Render, Fly, Railway, etc.) are usually reachable when attached to your service. For self-hosted or external DBs, open that port in the firewall and allow your app's outbound IPs (or use a private network).
>
> **2. The database user must have access to the database.**  
> The user in `DB_USERNAME` must be allowed to connect to the DB host and must have the right privileges on the database in `DB_DATABASE` (e.g. `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and for migrations `CREATE`, `ALTER`). On managed services this is normally set when you create the DB and user; on your own server, grant the user access to the database and ensure it can connect from the app's network.
>
> **If the container fails to start or you see “No open ports”:** Check the deploy logs. The entrypoint (`docker-entrypoint.sh`) runs migrations and other steps before starting Octane. If **migration fails** or another step fails, the script exits and no port is opened. The entrypoint prints a clear error (e.g. “ERROR: Migration failed…”) and a hint with common causes (database not reachable, DB user has no access, missing env vars). Fix the cause and redeploy.

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

For stateless deployment, **remove all existing `SESSION_*` variables** from `.env.example` and your platform’s env, then set **only these three**. Leave every other session setting as Laravel’s default (in `config/session.php`) so sessions work correctly across instances and behind load balancers.

- **Driver**: `SESSION_DRIVER=database` (or `redis`).
- **Domain**: Set to your root domain with a leading dot so the cookie is valid across subdomains (e.g. `.yourdomain.com`; use `.example.com` only as a placeholder and replace with your real domain).
- **Lifetime**: Session lifetime in minutes (e.g. `120` = 2 hours).
- **Table**: Laravel’s default migration creates `sessions`. Ensure migrations are run on deploy (entrypoint does this for web).

```env
SESSION_DOMAIN=.example.com
SESSION_DRIVER=database
SESSION_LIFETIME=120
```

Replace `.example.com` with your actual root domain (e.g. `.yourdomain.com`). Do **not** add other `SESSION_*` vars (e.g. `SESSION_CONNECTION`, `SESSION_TABLE`, `SESSION_SECURE_COOKIE`, etc.); let Laravel use its defaults.

### 1b. Logging → single + stderr

Set **`LOG_STACK=single,stderr`** so logs are written to both the single log file and stderr. Your platform (Render, etc.) captures stderr, so you get logs in the dashboard without reading files. Laravel’s default `config/logging.php` stack channel uses `LOG_STACK` when set.

```env
LOG_STACK=single,stderr
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
| Sessions       | Only `SESSION_DOMAIN`, `SESSION_DRIVER`, `SESSION_LIFETIME` (remove other `SESSION_*`; see above) |
| Logging        | `LOG_STACK=single,stderr` (so platform captures logs from stderr) |
| Cache          | `CACHE_STORE=database` or `redis` |
| Uploads/files  | `FILESYSTEM_DISK=s3` (or other external disk) |
| Queue          | `QUEUE_CONNECTION=database` or `redis` |
| Broadcasting   | Optional: `BROADCAST_CONNECTION=pusher` (or reverb/ably) + driver-specific env vars |
| Logs           | Optional: ship `storage/logs` to external service; container logs go to Render stdout |

---

## Wayfinder (Laravel Wayfinder)

If you use **Laravel Wayfinder**, `scale:install` automatically removes the Wayfinder Vite plugin from `vite.config.*` (so the Docker frontend stage doesn't run PHP) and ensures `resources/js/routes/`, `resources/js/actions/`, and `resources/js/wayfinder/` are committed. Run **`php artisan wayfinder:generate`** locally after install, then commit the generated files. The image will then build without Wayfinder errors.

## Frontend build (Vite + Tailwind)

The frontend stage copies the full app source (respecting `.dockerignore`) before `npm run build`, so **Tailwind v3** (with `tailwind.config.js` and `postcss.config.js`) and **Tailwind v4** (with `@tailwindcss/vite` only) both work without any Dockerfile changes. Ensure `.dockerignore` does not exclude `tailwind.config.*` or `postcss.config.*` if you use v3.

## If `npm run build` fails (other causes)

If the frontend build still fails (e.g. missing env, other plugins), you can skip it and deploy without assets:

- **On Render**: In your Web Service → **Environment** → add a **Docker Build Argument**: name `SKIP_FRONTEND`, value `1`. The image will build with an empty `public/build` (no JS/CSS).
- **Local**: `docker build -f docker/Dockerfile --build-arg SKIP_FRONTEND=1 --build-arg DEPLOYMENT_TYPE=web -t app:latest .`

## Backend-only / API-only apps (no frontend)

If your app has **no Node frontend** (Vite, Blade with assets, etc.)—e.g. a pure API or backend service—run `php artisan scale:install`. The command will **ask** whether your app has a frontend in the same repo; answer **No** to use the backend-only Dockerfile. Or pass **`--no-frontend`** to skip the question and use the backend-only version. The install then sets `docker/Dockerfile` to the contents of `Dockerfile.backend` (no Node stage, faster builds). Both `Dockerfile` and `Dockerfile.backend` stay in `docker/` so you can switch later by overwriting `docker/Dockerfile` with `docker/Dockerfile.backend` or re-running the install and answering Yes to restore the full Dockerfile.

If you prefer to edit by hand, you can still remove the frontend parts from the Dockerfile manually:

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

1. **Web Service**: New → Web Service → connect repo → Environment: **Docker**. Set **Dockerfile Path** to **`docker/Dockerfile`** (required; in Advanced if not visible). **Port**: **8000**. Env: `DEPLOYMENT_TYPE=web`, `APP_KEY`, DB_*, and stateless vars. If `APP_NAME` contains spaces, wrap it in an extra single quotation (e.g. `APP_NAME='"Digitalize with AI"'`). For session: set only `SESSION_DOMAIN` (e.g. `.yourdomain.com`), `SESSION_DRIVER=database`, `SESSION_LIFETIME=120`; remove any other `SESSION_*` so Laravel defaults apply. Set **`LOG_STACK=single,stderr`** so the platform captures logs. Also set `CACHE_STORE=database`, etc. Leave **Start Command** empty so the image entrypoint runs.
2. **Worker**: New → Background Worker → same repo, Docker. Env: `DEPLOYMENT_TYPE=worker` and same DB/Redis/APP_KEY as web. Optional: build with `--build-arg DEPLOYMENT_TYPE=worker` for a smaller image.
3. Add a **PostgreSQL** (or MySQL) instance in Render and attach its URL to both services—or use SQLite for a single instance. The image supports all three out of the box. Prefer a **custom domain** (e.g. `app.yourdomain.com`) over the platform default (`*.onrender.com`) and set **`APP_URL`** to the exact URL your app’s DNS points to—with a load balancer, `APP_URL` must match the public URL or links, redirects, and assets can break.

See the package **README.md** for full step-by-step Render deployment instructions.
