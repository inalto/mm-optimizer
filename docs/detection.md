# Detection

The capability engine is the reason this plugin exists. It answers a question
most optimization plugins skip: what can this server actually do?

## Claimed versus verified

Every format carries one of three states.

| State | Meaning |
|---|---|
| `absent` | The library does not offer the format at all. |
| `claimed` | The library says it supports it, but the test failed. |
| `verified` | A real encode succeeded and the output passed every check. |

The middle state is the one that matters. `Imagick::queryFormats()` routinely
lists `WEBP` on installations where the delegate is broken and `writeImage()`
throws at runtime. A `cwebp` found on the `PATH` is sometimes a wrapper script
that writes nothing. Both report success to a naive check, and both produce
corrupted files in silence.

A format that is `claimed` but not `verified` stays **disabled**, and the
diagnostics screen says why.

## The five stages

### Stage 0 — environment

Managed-host detection comes first, by sniffing known constants
(`WPCOMSH_VERSION`, `PANTHEON_ENVIRONMENT`, `WPE_PLUGIN_VERSION`,
`FLYWHEEL_CONFIG_DIR`, `KINSTAMU_VERSION`, `WPNET_INIT_PLUGIN_VERSION`,
`WPAAS_V2_SITE_ID`). A hit skips binary discovery entirely — those binaries
would not be executable, so probing for them is wasted work and wasted warnings.

Then `exec`/`proc_open` availability, checked through a helper that parses both
`disable_functions` and suhosin's `executor.func.blacklist`, since either can
neutralise a function while leaving the symbol defined.

### Stage 1 — declared support

`gd_info()`, `Imagick::queryFormats()`, `Gmagick::queryFormats()`. Recorded as
`claimed`. Nothing is enabled on this evidence.

### Stage 2 — the functional test

For each candidate, `assets/probe/probe.png` (64×64, true colour with alpha) is
encoded to a temporary file inside the uploads directory — deliberately not
`/tmp`, which `open_basedir` frequently puts out of reach. The output then has
to pass all of:

1. the file exists and is non-empty;
2. the magic bytes match the target format — never the extension, never `finfo`;
3. the size is under a known ceiling, proving it actually compressed rather than
   wrapping raw pixels in a container;
4. it can be decoded back into an image of the right dimensions.

Only then is `verified` set. Measured ceilings with GD 2.3.3: WebP q82 = 1,018 B,
AVIF q58 = 1,081 B, PNG z9 = 840 B, JPEG q82 = 1,930 B. The ceilings sit at
roughly three times those figures.

### Stage 3 — system binaries

Searched in explicit directories (`/usr/bin`, `/usr/local/bin`, `/bin`,
`/opt/local/bin`, `/opt/homebrew/bin`, `/usr/gnu/bin`, `/usr/syno/bin`), never
resolved through a shell. Every `is_file()` goes through an `open_basedir`-aware
wrapper so probing never emits warnings.

Lossless optimizers get a different sample: `probe-bloated.jpg`, a real JPEG
padded with about 6 KB of junk comment. A binary that does nothing cannot get
the file under the threshold, so a placeholder script fails the test.

Every `proc_open` call takes an argv array — never a shell string — with a
timeout, and the whole stage is abandoned after 5 seconds.

### Stage 4 — ranking

Verified encoders are measured against `assets/probe/bench.jpg`, a 512×384
synthetic photograph, and ordered by bytes produced with milliseconds as the
tie-breaker.

**Why a second, larger sample.** "Does it work?" and "which compresses best?"
are different questions. On the 64×64 verification sample, `cwebp -m 6` comes
out *two bytes larger* than `-m 4`. On the benchmark photograph it is 7% smaller
— 24,822 B against GD's 26,736 B — matching what it does on real images.
Ranking on the tiny sample would pick the wrong engine.

There is no hand-written preference table. On a host where GD wins, GD wins.

### Stage 5 — delivery conditions

`mod_rewrite` availability, `.htaccess` writability, and an HTTP HEAD request
against a bundled `probe.avif` to read back the `Content-Type` the server
actually sends. See [Delivery](delivery.md) for why that last check matters.

## Caching and invalidation

Results live in the `mm_optimizer_capabilities` option with `autoload = no` —
**not** a transient. With a Redis object cache in play, a flush would evict a
transient and force a fresh detection run, `proc_open` calls included, on a
random front-end request. An option is durable and is only read in the admin,
in the queue worker, and on the first conversion.

Invalidation is by fingerprint rather than expiry:

```
sha1( PHP_VERSION | GD version | imagetypes() | Imagick version | gmagick
      | disable_functions | open_basedir | PHP_OS | plugin version | schema )
```

The fingerprint is recomputed on every read, which costs microseconds, and a
mismatch triggers re-detection. That catches a PHP upgrade or a newly enabled
extension the moment it happens, with no TTL to guess at.

Stage 3 is the exception: a host can install `jpegoptim` without changing
anything in the fingerprint, so binary discovery is re-run weekly regardless.

A 60-second `mm_opt_cap_lock` transient stops two concurrent requests from both
probing. Detection is also scheduled as a one-off cron event 30 seconds after
activation, so the first admin page load already has data.
