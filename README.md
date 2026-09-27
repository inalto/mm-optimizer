# MM Optimizer

WordPress image optimization plugin. It detects the graphics libraries
available on the host, verifies which ones actually work, and proposes the
optimal settings for that specific server.

## Why it exists

Existing optimization plugins share two recurring flaws.

The first is that they **trust declared support**. `Imagick::queryFormats()`
lists `WEBP` even when the delegate is broken; a `cwebp` on the `PATH` can be a
placeholder script. The result is corrupted files written in silence, noticed
weeks later.

The second is that they **push the decision onto the user**: dozens of switches
and sliders with no indication of which ones make sense on that host.

MM Optimizer answers both with a detection engine that genuinely tries to
encode, and a recommendation engine that turns the outcome into a justified
configuration.

## Documentation

Technical reference lives in [`docs/`](docs/); installation and day-to-day use
live in the [wiki](https://github.com/inalto/mm-optimizer/wiki).

- [Detection](docs/detection.md) — the five-stage capability engine
- [Optimization levels](docs/optimization-levels.md) — the presets and the measurements behind them
- [Delivery](docs/delivery.md) — responsive `<picture>`, and the AVIF MIME-type trap
- [Conflicts](docs/conflicts.md) — standing down for competing plugins
- [Hooks and API](docs/hooks.md) — filters and public functions
- [Database](docs/database.md) — schema and the claim protocol
- [Troubleshooting](docs/troubleshooting.md) — reading the diagnostics screen

## Architecture

```
mm-optimizer.php          bootstrap: constants, require list, activation hooks
includes/
  class-base.php          disable_functions- and suhosin-aware function_exists(),
                          open_basedir-aware is_file(), MIME from magic bytes,
                          relativized paths that survive a site move
  class-options.php       single serialized option, dot-notation reads,
                          strict-whitelist sanitization
  class-presets.php       the four optimization levels
  class-log.php           bounded diagnostic log
  class-conflicts.php     three detection signals, stand-down matrix
  class-probe.php         the functional ("blind") test
  class-capabilities.php  the detection engine
  class-recommender.php   from capabilities to settings, with the reasons
  class-paths.php         the only place that knows the naming scheme
  class-encoder.php       GD / Imagick / CLI encoding
  class-records.php       tracking table
  class-thumbnails.php    lossless optimization, thumbnails only
  class-converter.php     derivative production and its safety rules
  class-lock.php          dual transient + lockfile lock
  class-queue.php         work queue and background worker
  class-scanner.php       builds a bulk job
  class-migrator.php      import from Converter for Media
  class-attachment-hooks.php  attachment lifecycle
  class-htaccess.php      marker block management
  delivery/               srcset recorder, <picture> rewriting, mode selection
  api.php                 public API in the global namespace
admin/                    menu, tabbed settings page, diagnostics, bulk, AJAX
assets/probe/             deterministic test samples
```

## Detection, in five stages

1. **Environment** — managed host (known constants), `exec` availability,
   `open_basedir`, memory, time limit. On a managed host, binary discovery is
   skipped entirely: they would not be executable anyway.
2. **Declared support** — `gd_info()`, `Imagick::queryFormats()`,
   `Gmagick::queryFormats()`. Recorded as `claimed`, never as `verified`.
3. **Functional test** — for every (library, format) pair it encodes
   `assets/probe/probe.png`, then checks: the file exists, is not empty, the
   magic bytes match, the size is under a known ceiling, and the result can be
   read back. Only then is `verified` set.
4. **System binaries** — searched in explicit directories, never resolved by a
   shell, every `is_file()` filtered through `open_basedir`. Lossless
   optimizers are tested against a JPEG deliberately padded with metadata: a
   binary that does nothing cannot shrink it.
5. **Ranking** — the verified encoders are measured against a 512×384 test
   photograph and ordered by bytes produced, with milliseconds as the
   tie-breaker.

### Why two different samples

"Does it work?" and "which compresses best?" are different questions and need
different samples. On the 64×64 sample used for verification, `cwebp -m 6` comes
out two bytes **larger** than `-m 4`; on the test photograph it is 7% smaller,
consistent with what it does on real images. Ranking on the wrong sample picks
the wrong engine.

## Cache invalidation

Capabilities live in the `mm_optimizer_capabilities` option with
`autoload = no`, not in a transient: with a Redis object cache, a flush would
force a fresh detection run — `proc_open` calls included — on some random
front-end request.

Invalidation is by **fingerprint**, not by expiry:

```
sha1( PHP_VERSION | GD version | imagetypes() | Imagick version | gmagick
      | disable_functions | open_basedir | PHP_OS | plugin version | schema )
```

A PHP upgrade or a newly enabled extension is caught the moment it happens, with
no TTL to guess. The exception is binary discovery, re-examined weekly anyway: a
package can appear without anything in the fingerprint changing.

## Standing down for competitors

Three signals, cheapest first: active plugin basenames, runtime symbols (for
renamed folders), and markers in `.htaccess` files (for deactivated plugins that
left live rules behind).

| Subsystem | With a conflict active |
|---|---|
| `<picture>` and `.htaccess` delivery | off, no hooks registered |
| Conversion, queue, bulk conversion | **on** |
| Thumbnail optimization | off only when EWWW is active |
| Diagnostics and recommendations | on |

The `MM_OPTIMIZER_FORCE_DELIVERY` constant in `wp-config.php` overrides the
stand-down for testing, and says so with an admin notice.

## Developer hooks

| Filter | Purpose |
|---|---|
| `mm_optimizer_quality` | quality chosen for a conversion |
| `mm_optimizer_blocks` | stand-down decision for a subsystem |
| `mm_optimizer_encoder_for` | engine used for an output format |
| `mm_optimizer_binary_paths` | directories searched for binaries |
| `mm_optimizer_webpc_dir` | Converter for Media output directory |

Public functions: `mm_optimizer_get_option()`, `mm_optimizer_capabilities()`,
`mm_optimizer_recommendation()`, `mm_optimizer_supports()`.

## Wiki

The user handbook is kept under [`wiki/`](wiki/) and published to the
[GitHub wiki](https://github.com/inalto/mm-optimizer/wiki) with
`wiki/publish.sh`. Keeping the sources in the repository means the pages are
version-controlled and reviewable alongside the code.

## Translations

Source strings are English. An Italian translation ships in
`languages/mm-optimizer-it_IT.po`.

## License

GPL-3.0-or-later.
