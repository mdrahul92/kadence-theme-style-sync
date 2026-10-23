# Theme Style Sync for Kadence Blocks

Feeds the active theme's global styles (palette, extra colors, text/link colors, buttons, fonts) into the Kadence Blocks Style Library (Kadence Blocks 3.8+) as its defaults. Works with any block theme through WordPress global styles, with a richer adapter for Blocksy. See `readme.txt` for details.

## Releasing

Sites update themselves from this repository's GitHub Releases (Dashboard → Updates, auto-updates on by default).

1. Bump the version in `kadence-theme-style-sync.php` (both the `Version:` header and `KTSS_VERSION`) and `Stable tag` in `readme.txt`.
2. Commit and push.
3. Tag and push:
   - Stable, to every site: `git tag v1.2.1 && git push origin v1.2.1`
   - Beta, only to sites on the beta channel (staging): `git tag v1.3.0-beta.1 && git push origin v1.3.0-beta.1`

The Release workflow checks the tag matches the plugin version, lints, builds `kadence-theme-style-sync.zip` and publishes the release. Sites see it within 12 hours, or right away with **Check now** on the plugin's Overview screen.

To promote a beta, tag the same code as a normal version (e.g. `v1.3.0`).

## Update channel

Per site on the plugin's Overview screen, or for a whole server in `wp-config.php`:

```php
define( 'KTSS_UPDATE_CHANNEL', 'beta' ); // or 'stable'
```

## Private repository (optional)

The repository is public, so sites need no token. If it is ever made private, every site needs a read-only fine-grained token (Contents: read, this repository only) in `wp-config.php`:

```php
define( 'KTSS_GITHUB_TOKEN', 'github_pat_…' );
```
