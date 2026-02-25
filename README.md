# Laravel Scale

[![Latest Version on Packagist](https://img.shields.io/packagist/v/provydon/laravel-scale.svg?style=flat-square)](https://packagist.org/packages/provydon/laravel-scale) [![GitHub release (latest SemVer)](https://img.shields.io/github/v/release/provydon/laravel-scale?style=flat-square)](https://github.com/provydon/laravel-scale/releases)

Scale your Laravel app with one install: it comes with **Laravel Octane (FrankenPHP)**, a production-ready Docker setup, and a stateless web + worker layout that runs on Render, Laravel Cloud, Fly.io, Railway, and other container platforms.

---

[![Buy me a coffee](https://img.shields.io/badge/Buy_me_a_coffee-FFDD00?style=for-the-badge&logo=buy-me-a-coffee&logoColor=black)](https://buymeacoffee.com/provydon)

---

## Why you need this

A traditional Laravel deployment runs a single PHP process on the server (e.g. `php artisan serve` or one PHP-FPM worker). When your app gets 100,000 requests in a minute, they all queue up to that one process—bottleneck, timeouts, and a bad experience. Users at the bottom of that queue experience slow or failing requests.

With Laravel Scale, your app is containerized with Docker and can replicate into 10, 50, or 100+ instances automatically (autoscale).

Deployment platforms like Render, Laravel Cloud, Fly.io, and Railway run on Kubernetes (or similar orchestration) under the hood, so your container is scheduled and scaled for you—**you don’t need to know or manage Kubernetes yourself.** The platform’s load balancer spreads those 100k requests across the running containers.

For example, your web service can autoscale to 100 instances; that’s about 1,000 requests per instance—all of them process fast for users instead of piling up on a single process.

This package gives you that setup in one command: Octane for high-concurrency HTTP, a web + worker layout, and the stateless config (session, cache, files) so multiple instances work together instead of fighting each other.

---

## Contents

- [Install](#install)
- [After install](#after-install)
- [What it does](#what-it-does)
- [Deploying on Render.com](#deploying-on-rendercom)
- [Typical dev journey](#typical-dev-journey)
- [Local development](#local-development)
- [Contributing (install from local path)](#contributing-install-from-local-path)
- [Support](#support)

---

## Install

Run `scale:install` **once** from your local machine. The published files become part of your repo—commit them and push. CI and deployment platforms (Render, Laravel Cloud, Fly.io, etc.) build from the repo; they do not run `scale:install` again.

**From [Packagist](https://packagist.org/packages/provydon/laravel-scale):**

```bash
composer require provydon/laravel-scale --dev --with-all-dependencies
php artisan scale:install
```

Then commit `docker/`, `.dockerignore`, `app/Providers/ForceHttpsServiceProvider.php`, `app/Http/Middleware/ForceHttpsMiddleware.php`, `bootstrap/providers.php`, `bootstrap/app.php`, and `config/octane.php`. Run `composer update` to pull the latest Octane and other dependencies.

**When you upgrade the package** (e.g. `composer update provydon/laravel-scale --with-all-dependencies`), run `php artisan scale:install` again to publish the latest Docker files and fixes, then commit any changed files.

This will:

1. Publish `docker/` (Dockerfile, Dockerfile.backend, entrypoint, supervisor configs, php.ini). Then **ask** whether your app has a frontend (Vite, Blade with assets, etc.); if no (or you pass `--no-frontend`), the main `docker/Dockerfile` is set to the backend-only version (no Node stage). Both variants are in `docker/`; you can switch later by overwriting `Dockerfile` with `Dockerfile.backend` or re-running the install.
2. Publish `.dockerignore`.
3. Run `octane:install --server=frankenphp` (unless you pass `--no-octane`).
4. Update `.gitignore` so `docker/` and `config/octane.php` are committed (unless you pass `--no-gitignore`).
5. If you use **Laravel Wayfinder**: remove the Wayfinder plugin from `vite.config.*` (so `npm run build` in Docker doesn't run PHP) and add `!resources/js/routes/`, `!resources/js/actions/`, and `!resources/js/wayfinder/` to `.gitignore` so generated files are committed. Run `php artisan wayfinder:generate` locally and commit the output before deploying.

**Choosing full vs backend-only Dockerfile**

The install publishes two Dockerfiles: **`docker/Dockerfile`** (full: Node + PHP, builds Vite/assets) and **`docker/Dockerfile.backend`** (PHP only, no Node). It then sets which one is the *main* `docker/Dockerfile`:

- **Interactive:** You are asked *"Does this app have a frontend in the same repo (React, Vue, Svelte, Vite, etc.)?"* — **Yes** (default) keeps the full Dockerfile; **No** overwrites `docker/Dockerfile` with the backend-only version. If you don’t answer within **8 seconds**, it defaults to full (full stack).
- **`--no-frontend`:** Skips the question and uses the backend-only Dockerfile (for API-only apps).
- **Non-interactive (e.g. CI):** No question is asked; the full Dockerfile is kept.

You can switch later by overwriting `docker/Dockerfile` with `docker/Dockerfile.backend`, or re-run `scale:install` and answer the question to restore the full one.

Options:

- `--no-octane` – Only publish files; don’t run Octane install.
- `--no-dockerignore` – Don’t overwrite `.dockerignore`.
- `--no-gitignore` – Don't update `.gitignore` (ensures `docker/` and `config/octane.php` are committed).
- `--no-wayfinder` – Skip Wayfinder Vite and .gitignore adjustments.
- `--no-frontend` – Use the backend-only Dockerfile (no Node/Vite); for API-only apps. Without this, `scale:install` asks whether your app has a frontend and sets `docker/Dockerfile` accordingly (full or backend-only).

## After install

1. **Octane in production**  
   `scale:install` adds or moves **`laravel/octane` into `require`** in your `composer.json` so the Docker image (which runs `composer install --no-dev`) gets Octane. The package is in `require-dev` so production’s `composer install --no-dev` won’t install it; the published files run in production.

2. **Database**  
   The Docker image includes **MySQL**, **PostgreSQL**, and **SQLite** drivers by default (`pdo_mysql`, `pdo_pgsql`, `pdo_sqlite`). Set `DB_CONNECTION` and your DB_* env vars (e.g. on Render) and it works. **See the “Database” section under *Deploying on Render* below** for essential requirements: your database must be reachable from your containers (correct ports open) and the DB user must have access to the database.

3. **Stateless setup**  
   In Render (and `.env.example`), set:
   - **Session**: `SESSION_DRIVER=database` (or `redis`).
   - **Cache**: `CACHE_STORE=database` (or `redis`).
   - **Files**: `FILESYSTEM_DISK=s3` and AWS_* (or other external disk).
   - **Queue**: `QUEUE_CONNECTION=database` (or `redis`) for the worker service.

4. **Docker**  
   - Web: build with `DEPLOYMENT_TYPE=web`, expose port **8000**.
   - Worker: same image with `DEPLOYMENT_TYPE=worker`, or build with `--build-arg DEPLOYMENT_TYPE=worker` for a smaller image.

5. **Render**  
   - Web: Docker service, build from repo, start command = container default (entrypoint), port 8000.
   - Worker: second Docker service, same image, `DEPLOYMENT_TYPE=worker`, no port.

### Why separate web and worker-scheduler services?

You need **both** a web service and a **worker-scheduler** service (Background Worker on Render):

- **Scheduler**: Running the scheduler (`schedule:work`) on a single dedicated worker avoids race conditions. If every web container ran the scheduler, multiple instances could trigger the same task at once (e.g. duplicate emails or cleanup jobs).
- **Queue and HTTP**: Running `queue:work` and the scheduler on the worker keeps background work off the web processes. That way web containers stay focused on handling requests instead of being slowed or blocked by queued jobs and cron.

See **docker/README.md** (published into your app) for the full stateless checklist, build commands, **PHP version** (how the image picks PHP 8.2–8.5 and how to pin a version), **database** (the image includes MySQL, PostgreSQL, and SQLite drivers by default—no Dockerfile edits needed), and **backend-only apps** (how to remove the Node/frontend stage if your app is API-only).

## What it does

`scale:install` publishes into your app:

- **docker/** — Dockerfile (PHP + Node frontend stage; MySQL, PostgreSQL, SQLite drivers; PostCSS/Tailwind support), entrypoint, `supervisord-web.conf` (Octane), `supervisord-worker.conf` (queue + scheduler), php.ini
- **.dockerignore** — keeps build context small
- **app/Providers/ForceHttpsServiceProvider.php** — forces `https://` for URLs in non-local environments (so production works behind a reverse proxy without Mixed Content)
- **app/Http/Middleware/ForceHttpsMiddleware.php** — runs at the start of the web stack so asset/Vite URLs use `https://` (avoids blank or unstyled pages from Mixed Content)
- **docker/README.md** — stateless checklist (session/cache in DB or Redis, files on S3), PHP version, database (all three drivers by default), backend-only variant

**Requirements:** PHP ^8.2, Laravel ^11.0|^12.0, laravel/octane ^2.13 (FrankenPHP). Run `composer update` in your app to pull compatible versions.

## Deploying on Render.com

> **Before you deploy to production:** Set **`APP_ENV=production`** in your Web and Worker environment variables on Render (or your platform). If you leave `APP_ENV=local` or leave it unset, the app may generate `http://` asset URLs and the page can appear blank (Mixed Content blocked by the browser). Set **`APP_URL`** to your production URL with `https://` (e.g. `https://myapp.onrender.com` or your custom domain) as well.

**Important:** Set **Dockerfile Path** to `docker/Dockerfile` for both Web and Worker services (Render needs this because the Dockerfile lives in the `docker/` folder).

### 1. Create a Web Service (Docker)

1. In [Render Dashboard](https://dashboard.render.com), click **New +** → **Web Service**.
2. Connect your repo (GitHub/GitLab).
3. Configure:
   - **Name**: e.g. `myapp-web`
   - **Region**: choose one
   - **Environment**: **Docker**
   - **Dockerfile Path**: **`docker/Dockerfile`** (required—set in **Advanced** if not visible).
   - **Build Command**: leave default (Render runs `docker build` from repo root) or set:
     ```bash
     docker build -f docker/Dockerfile --build-arg DEPLOYMENT_TYPE=web -t app:latest .
     ```
   - **Start Command**: leave empty so the image’s **ENTRYPOINT** runs (entrypoint starts Octane via Supervisor).
   - **Instance Type**: pick size (e.g. Starter or higher).
4. **Environment variables** (Add Environment Variable):
   - `APP_KEY` (generate with `php artisan key:generate --show` locally).
   - `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://your-domain.com` (use your own domain; platform defaults like `*.onrender.com` can cause session issues).
   - `DEPLOYMENT_TYPE=web`.
   - Database: `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`. Use any database (PostgreSQL, MySQL, SQLite, etc.); on Render you can add a PostgreSQL instance and copy its env vars.
   - Stateless: `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, and if using S3: `FILESYSTEM_DISK=s3`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`.
   - Optional—broadcasting: `BROADCAST_CONNECTION=pusher` (or reverb/ably) and driver-specific env vars (e.g. `PUSHER_APP_ID`, `PUSHER_APP_KEY`, etc.) on both Web and Worker.
5. **Port**: set **Port** to **8000** (Octane listens on 8000).
6. Save and deploy. Render will build the Docker image and start the web service.

**Set `APP_URL` correctly.** Use either the URL your platform gives you (e.g. `https://myapp.onrender.com`) or your custom domain (e.g. `https://app.yourdomain.com`). If `APP_URL` is wrong, the app can load over HTTPS but request assets over HTTP. The browser will block those requests (Mixed Content), and the page will appear blank—e.g. *"The page at 'https://yoursite.onrender.com/login' was loaded over HTTPS, but requested an insecure resource 'http://yoursite.onrender.com/build/assets/...'. This request has been blocked."* Fix it by setting `APP_URL` to the exact public URL (including `https://`) in the Web Service environment variables, then redeploy.

If **`npm run build` fails** (e.g. Vite/Wayfinder errors): add a Docker build argument **`SKIP_FRONTEND`** = **`1`** in the service’s Environment so the image builds without frontend assets. See **docker/README.md** for details.

### 2. Create a Worker Service (optional)

1. **New +** → **Background Worker**.
2. Same repo; **Environment**: **Docker**.
3. **Dockerfile Path**: **`docker/Dockerfile`** (same as Web).
4. **Build Command** (optional, for slimmer image):
   ```bash
   docker build -f docker/Dockerfile --build-arg DEPLOYMENT_TYPE=worker -t app-worker:latest .
   ```
5. **Start Command**: leave empty (entrypoint uses `DEPLOYMENT_TYPE` to start queue + scheduler).
6. **Environment variables**: same as web (DB, Redis if used, `APP_KEY`, etc.) plus:
   - `DEPLOYMENT_TYPE=worker`
7. No port needed. Deploy.

### 3. Render-specific env vars

Render injects some vars automatically (e.g. `RENDER_EXTERNAL_URL`, `RENDER_INSTANCE_ID`). The Docker entrypoint skips copying `RENDER_*` from host env into `.env` so they don’t overwrite; your app can still read them from `getenv()` or `$_ENV` if needed.

### 4. Database

Use any database Laravel supports: **PostgreSQL**, **MySQL**, or **SQLite**. The Docker image includes all three drivers by default—no Dockerfile edits needed. Set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in your Web and Worker services. On Render, create a PostgreSQL or MySQL instance (or use SQLite for a single instance) and add its connection vars.

> **Important: make your database reachable and grant access**
>
> Your app runs inside containers. For migrations and normal requests to work, two things must be true.
>
> **1. The database must be reachable from your containers.**  
> If the DB runs on a separate host (managed service or your own server), it has to accept connections from the internet or your platform’s private network. The right **port must be open**: **3306** for MySQL, **5432** for PostgreSQL. On Render, attaching a managed PostgreSQL or MySQL instance does this for you. For a self-hosted or external DB, open that port in the firewall and allow your platform’s outbound IPs (or put the DB and app on a private network).
>
> **2. The database user must have access to the database.**  
> The user in `DB_USERNAME` must be allowed to connect to the host and must have privileges on the database named in `DB_DATABASE` (e.g. `SELECT`, `INSERT`, `UPDATE`, `DELETE`, and for migrations `CREATE`, `ALTER`, etc.). On managed services this is usually set when you create the DB and user; on your own server, grant the user access to the database and ensure it can connect from the app’s network.
>
> **If the container fails to start or you see "No open ports":** Check the deploy logs. The entrypoint runs migrations and other steps before starting the web server; if **migration fails** or another step fails, the container exits and no port is opened. The entrypoint prints a clear error (e.g. "ERROR: Migration failed…") and a short hint on common causes (database not reachable, DB user has no access, missing env vars). Fix the cause (open port 3306/5432, grant the user access to the database, or set APP_KEY and DB_*), then redeploy.

### 5. Redis or key-value cache (optional)

If you use Redis for cache, session, or queue, add the PHP Redis extension to the Dockerfile first: in the `install-php-extensions` block, add `redis \` on its own line before the other extensions. Then deploy a Redis or key-value cache service (e.g. Render’s Redis add-on, Upstash, or similar) and point the web service at it via env configs. Set `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, and/or `QUEUE_CONNECTION=redis`, then add the connection vars (`REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT`, etc.) to your Web and Worker services. No code changes—Laravel reads these from the environment.

### 6. Custom domain and HTTPS

Use **your own domain or subdomain** for the app instead of the platform’s default (e.g. `*.onrender.com`). Platform default domains can cause session handling issues and sessions may invalidate unexpectedly. Add your domain in the Web Service → **Settings** → **Custom Domains**; Render provides TLS. **Set `APP_URL` to the exact URL your app’s DNS points to** (e.g. `https://app.yourdomain.com`). When the app is behind a load balancer, `APP_URL` must match the public URL or links, redirects, and assets can break.

`scale:install` adds **`trustProxies(at: '*')`** and **`statefulApi()`** to `bootstrap/app.php` so that behind a reverse proxy (e.g. Render) the app uses the forwarded protocol and generates `https://` asset URLs. Without this, the page can load over HTTPS but request CSS/JS over HTTP (mixed content), and the frontend may appear blank.

---

## Typical dev journey

1. **Local setup** – Create your Laravel app, develop as usual (Blade, Inertia, API, etc.).
2. **Install once** – When ready to deploy: `composer require provydon/laravel-scale --dev --with-all-dependencies` and `php artisan scale:install`.
3. **Commit** – Commit `docker/`, `.dockerignore`, `app/Providers/ForceHttpsServiceProvider.php`, `app/Http/Middleware/ForceHttpsMiddleware.php`, `bootstrap/providers.php`, `bootstrap/app.php`, `config/octane.php`, and the `.gitignore` changes. Push to GitHub/GitLab.
4. **Stateless config** – In `.env.example` (and your platform’s env), set `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`. Use S3 for uploads. Add Redis extension to Dockerfile if using Redis.
5. **Platform** – Create a Web Service (Docker) and a Background Worker on Render (or similar). Point both at your repo. Set **Dockerfile Path** to `docker/Dockerfile` for each. Add a database (e.g. PostgreSQL on Render) and set env vars, deploy.
6. **Iterate** – Push code; platform rebuilds from the repo. No `scale:install` in CI—everything is already in the repo.

**Local testing (optional):** Run `docker build -f docker/Dockerfile --build-arg DEPLOYMENT_TYPE=web -t app:latest .` and `docker run -p 8000:8000 -e APP_KEY=base64:xxx -e DB_*="..." app:latest` to test the image locally.

---

## Local development

Use Laravel Octane as usual, e.g.:

```bash
php artisan octane:start --server=frankenphp
```

Or your existing `composer dev` / `npm run dev` setup; the package only adds Docker and publishables for deployment.

## Contributing (install from local path)

If you’re developing this package or want to try it from a local clone, in your Laravel app’s `composer.json` add:

```json
"repositories": [
  { "type": "path", "url": "/path/to/laravel-scale" }
],
"require-dev": {
  "provydon/laravel-scale": "@dev"
}
```

Then run `composer update provydon/laravel-scale --with-all-dependencies` and `php artisan scale:install`.

## Support

Enjoying Laravel Scale? [Buy me a coffee](https://buymeacoffee.com/provydon) — it helps keep this project maintained.

## License

MIT.
