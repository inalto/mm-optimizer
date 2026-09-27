# Conflicts and standing down

Two image optimizers rewriting the same URLs get in each other's way. Rather
than compete, MM Optimizer detects the situation and switches off the parts that
would collide — while keeping the parts that would not.

## Three detection signals

**Active plugin basenames.** Authoritative, and cheapest. Checked through both
`is_plugin_active()` and `is_plugin_active_for_network()`:

```
ewww-image-optimizer/ewww-image-optimizer.php
ewww-image-optimizer-cloud/ewww-image-optimizer-cloud.php
webp-converter-for-media/webp-converter-for-media.php
converter-for-media/converter-for-media.php
imagify/imagify.php
shortpixel-image-optimiser/wp-shortpixel.php
shortpixel-adaptive-images/short-pixel-ai.php
wp-smushit/wp-smush.php
wp-smush-pro/wp-smush.php
optimole-wp/optimole-wp.php
```

**Runtime symbols.** Catches renamed folders and mu-plugin copies:
`EWWW_IMAGE_OPTIMIZER_VERSION`, `WebpConverter\Plugin_Data`, `IMAGIFY_VERSION`,
`ShortPixel\Controller\OptimizeController`, `WP_Smush`, `OPTML_VERSION`.

**`.htaccess` markers.** Scans the root, `wp-content` and uploads `.htaccess`
files for `BEGIN Converter for Media`, `BEGIN EWWWIO`, `EWWWIO_WEBP` and
`BEGIN ShortPixel`. This catches the genuinely dangerous case: a *deactivated*
plugin that left live rewrite rules behind. Cached for 15 minutes.

## The stand-down matrix

| Subsystem | With a conflict active |
|---|---|
| `<picture>` delivery | Off — not a single hook is registered |
| srcset recording | Off |
| `.htaccess` block | Never written, and any block we previously wrote is removed |
| **Conversion, queue, bulk** | **On** |
| Thumbnail lossless optimization | On, except when EWWW is active |
| Diagnostics and recommendations | On |
| Migrator | On — this is exactly when it is useful |

Conversion staying on is the point. The derivatives get built while the other
plugin is still serving, so when you deactivate it there is nothing to wait for.

Thumbnail optimization is the one exception that keys on a specific plugin: EWWW
rewrites the same thumbnail files in place, and two optimizers writing one file
is a real corruption risk.

### A scope subtlety

Signal 3 alone — markers present, no plugin active — blocks only the `.htaccess`
subsystem, **not** `<picture>`. Stale rewrite rules break `Accept` negotiation
but are harmless to `<picture>` output, so there is no reason to disable it.

### Override

`define( 'MM_OPTIMIZER_FORCE_DELIVERY', true );` in `wp-config.php` overrides the
stand-down for testing. While it is set, an admin notice says so — this is not a
setting, and it is not meant to be left on.

## Migrating from Converter for Media

Converter for Media writes into a parallel tree, `wp-content/uploads-webpc/`,
mirroring the source path and appending the new extension:
`uploads-webpc/uploads/2024/01/photo.jpg.webp`. That naming scheme happens to
match this plugin's, so the files only need relocating.

The Bulk tab offers two actions when it detects an installation:

**Import the settings** reads `webpc_settings` and maps quality and enabled
formats onto ours. The quality is mapped to the nearest *level* rather than
copied into Custom, because the levels are calibrated against real measurements
and a single inherited number is not.

**Adopt the converted files** indexes the existing tree into the tracking table
and moves the files into place, turning a multi-hour re-encode into a directory
scan. It runs in passes of 200 so a large library does not hit the time limit.

Adoption is the quick route. Regeneration is usually the better one: Converter
for Media installations in the wild are overwhelmingly WebP-only, and AVIF is
where the remaining gain is. When the inventory shows fewer than one AVIF per
ten WebP files, the screen says so.
