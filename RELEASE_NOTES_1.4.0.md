# Laravel Scale v1.4.0

**Release date:** February 2025

## What's New

### Prominent reminder: set APP_ENV=production before deploying

The README now includes a **clear callout** at the start of the *Deploying on Render.com* section:

- **Set `APP_ENV=production`** in your Web and Worker environment variables on your platform (Render, Fly.io, etc.) before you deploy. If you leave `APP_ENV=local` or leave it unset, the app can generate `http://` asset URLs and the page may appear blank (Mixed Content blocked by the browser).
- **Set `APP_URL`** to your production URL with `https://` (e.g. `https://myapp.onrender.com` or your custom domain).

This reminder is placed so you see it as soon as you open the deployment instructions.

---

## Upgrade

```bash
composer update laravel-scale/laravel-scale
php artisan scale:install
```

No code changes required. When configuring production, set `APP_ENV=production` and `APP_URL=https://...` in your platform’s environment variables.

---

## Full changelog

- **Docs:** Prominent reminder at the start of *Deploying on Render.com* to set `APP_ENV=production` and `APP_URL` (with `https://`) before deploying to production.
