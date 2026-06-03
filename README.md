# Sync Elementor Cache

Keeps **Elementor** in sync with **WP Rocket** and/or **SiteGround Optimizer** so logged-out visitors don't see stale CSS after editor saves, library template changes, or plugin updates.

Auto-detects which caching layers are present and adapts. One plugin covers all four combos: Rocket-only, Rocket+SG, SG-only, neither.

## Why this exists

WP Rocket's built-in Elementor integration skips page-cache invalidation when Elementor's CSS print method is set to "internal" — which is the most common config. Result: editor save → reload in incognito → site looks broken until a manual purge. Same symptom on plugin/theme updates.

This plugin bridges the gap by hooking every event that should cause cache invalidation and routing it to whichever cache APIs are available on the host.

## What it hooks

| Event | Scope | Why |
|---|---|---|
| `elementor/core/files/clear_cache` | site-wide | Admin clicks "Regenerate Files & Data" |
| `elementor/document/after_save` | per-post or site-wide | Any Elementor document saved |
| `elementor/editor/after_save` | per-post or site-wide | Sister hook |
| `save_post_elementor_library` | site-wide | Direct save on a library template |
| `update_option_elementor_active_kit` | site-wide | Kit changed |
| `elementor/core/files/after_regenerate` | site-wide | Bulk regen finished |
| `save_post_custom_css` | site-wide | WP custom CSS edited |
| `save_post_wpcode` | site-wide | WPCode snippet saved |
| `upgrader_process_complete` + `automatic_updates_complete` | site-wide | Plugin / theme / core updates |
| `activated_plugin` / `deactivated_plugin` / `switch_theme` | site-wide | Obvious |
| `?sec_purge_all=1` on any URL | site-wide | Manual purge (admin only) |

Library templates and JetEngine listings always route to site-wide (they're embedded on many pages).

## What it purges (when present)

- **Elementor:** `\Elementor\Plugin::$instance->files_manager->clear_cache()` — regenerates all per-post CSS files
- **WP Rocket:** `rocket_clean_post`, `rocket_clean_domain`, `rocket_clean_minify('css')`, `rocket_clean_cache_busting`, `rocket_clean_used_css`
- **SiteGround Optimizer:** `sg_cachepress_purge_cache()` (per-post / soft) + `sg_cachepress_purge_everything()` (site-wide / nuclear)
- **WordPress:** `wp_cache_flush()`

The split between SG soft and SG nuclear matters: SG's full-page cache can hold cached error responses (e.g. a 403 from a transient WAF rate-limit) that the soft URL purge can't reach. Site-wide events use the nuclear purge so error responses don't get stuck.

## What it does NOT do

- **No `_elementor_css` postmeta watcher.** Elementor fires `updated_post_meta` for `_elementor_css` during normal frontend regeneration. Watching it triggers a regen → purge → regen loop that empties Elementor's CSS folder under traffic and causes MySQL deadlocks on busy sites. All real save scenarios are covered by the explicit hooks above.

## Installation

1. Download the latest [release zip](https://github.com/sansiromedia/sync-elementor-cache/releases).
2. Plugins → Add New → Upload Plugin → Install → Activate.
3. Settings → Sync Elementor Cache to see what's detected.

Once installed, the plugin auto-updates from GitHub releases — no further action needed.

## Admin UI

- **Detected cache layers** — at-a-glance view of what's active on the host
- **Last purge** — scope, post (if applicable), timestamp
- **Manual purge button** — same effect as `?sec_purge_all=1`

## WP-CLI

```bash
wp sync-elementor-cache status   # Show detected layers + last purge
wp sync-elementor-cache purge    # Trigger site-wide purge
```

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Elementor (Free or Pro) — the plugin is harmless without it but doesn't do anything useful
- At least one of: WP Rocket, SiteGround Optimizer

## Author

Pip Baddock — `github.com/sansiromedia`

## License

GPL-2.0-or-later. See `LICENSE`.

## Update history

- **v4.1.0** (2026-06-03) — Initial plugin release. Distilled from the v4 WPCode snippet pattern across multiple client sites. Adds auto-detection, admin UI, WP-CLI.
