# Releasing to Packagist

## One-time: Submit the package

1. Go to [packagist.org](https://packagist.org) and log in (or create an account).
2. Click **Submit** and enter the repository URL:
   ```
   https://github.com/provydon/laravel-scale
   ```
3. Click **Check** then **Submit**. Packagist will import the package and all git tags as versions.

After that, `composer require laravel-scale/laravel-scale` will work (use `--dev` for dev-only installs).

## For each new release

1. Bump the version in your mind (e.g. `1.0.1`, `1.1.0`).
2. Create and push an annotated tag:
   ```bash
   git tag -a v1.0.1 -m "Release 1.0.1"
   git push origin v1.0.1
   ```
3. Packagist will pick up the new tag within a few minutes (or use **Update** on the package page to refresh now).

That’s it. No need to change `composer.json` version—Packagist uses git tags.
