# The Diagnostics screen

This is the screen the plugin is built around. Everything on it comes from
measurements taken on your server, not from assumptions.

## The score

A single 0–100 figure, weighted towards the things that matter most: WebP
support (35), AVIF support (35), a lossless JPEG tool (10), a writable uploads
directory (10), and correct AVIF delivery conditions (10).

| Score | Reading |
|---|---|
| 80–100 | Everything needed is present |
| 50–79 | Usable, but something is missing — the reasons say what to ask for |
| 0–49 | Most of what is required is absent |

## The capability matrix

Formats down the side, libraries across the top.

| Cell | Meaning |
|---|---|
| **✓ verified** | A real encode succeeded and the output passed every check. Hover for the bytes and milliseconds. |
| **~ claimed** | The library says it supports this but the test failed. The format stays **off**. Hover for why. |
| **✗** | Not offered. |

The middle state is the important one. A library can report a format it cannot
actually write — this is common with Imagick builds whose delegate is broken.
Trusting that report is how sites end up with corrupted images discovered weeks
later. The plugin encodes a known 64×64 sample, checks the magic bytes, confirms
the size is plausible, and reads the result back before believing anything.

## Engine comparison

When more than one engine can produce a format, they are measured against the
same 512×384 test photograph and the smallest output wins, with speed as the
tie-breaker.

This uses a different, larger sample than the verification test on purpose. On a
64×64 image the differences between engines vanish — `cwebp -m 6` even comes out
two bytes *larger* than `-m 4`. On a photograph it is 7% smaller. Ranking on the
small sample would pick the wrong engine.

There is no built-in preference for one library over another. Whichever produces
the smallest file on your server wins.

## Recommended configuration

Every recommendation carries its reason. Items are marked:

- **✓ good** — a decision made, with the measurement behind it
- **! warning** — works, but something would improve it
- **✗ error** — a blocker, listed under *To resolve*

Click **Apply the recommended configuration** to write them all at once. The
values still pass through the same validation as manual edits.

## Environment

Raw facts: PHP version, memory limit, execution time, whether commands can be
run, `open_basedir`, whether uploads are writable, whether PHP file uploads are
enabled, whether `.htaccess` is writable, and the MIME type your server actually
returns for `.avif` files.

That last row is worth checking. If it says the server does not know
`image/avif`, see [Delivering images](Delivering-images#the-avif-mime-type-trap).
