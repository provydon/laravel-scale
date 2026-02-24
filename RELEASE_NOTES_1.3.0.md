# Laravel Scale v1.3.0

**Release date:** February 2025

## What's New

### Force HTTPS in production (without the package in prod)

The package is a **dev dependency** (`require-dev`), so in production `composer install --no-dev` does not install it. The previous HTTPS fix lived inside the package and never ran in production, so Mixed Content could still occur.

**In v1.3.0**, `scale:install` now:

1. **Publishes** `app/Providers/ForceHttpsServiceProvider.php` into your app. This provider forces `https://` for all generated URLs when the environment is not `local` (and for web requests, not console). It becomes part of your app code and runs in production.
2. **Registers** that provider in `bootstrap/providers.php` so Laravel loads it on every request.

Production no longer needs the package installed: your app’s own provider runs and ensures asset and link URLs use `https://`, avoiding Mixed Content behind reverse proxies (Render, Fly.io, etc.).

### Commit these files

After running `scale:install`, commit (in addition to existing files):

- `app/Providers/ForceHttpsServiceProvider.php`
- `bootstrap/providers.php` (if it was updated)

---

## Upgrade

```bash
composer update laravel-scale/laravel-scale
php artisan scale:install
```

Then commit any new or changed files (`app/Providers/ForceHttpsServiceProvider.php`, `bootstrap/providers.php`) and redeploy.

---

## Full changelog

- **Added:** Publish `ForceHttpsServiceProvider` into the app and register it in `bootstrap/providers.php` so production uses `https://` for URLs even when the package is not installed (`composer install --no-dev`).
- **Changed:** HTTPS forcing removed from the package’s own service provider; only the published app provider performs it.
- **Docs:** README updated to list the new provider and commit steps; “What it does” and “Done” message include the new files.
