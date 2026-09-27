# Troubleshooting

Almost every question starts at the same place: **the Diagnostics tab**. It
reports what was actually measured on this server, not what should be true.

## Reading the capability matrix

| Cell | Meaning |
|---|---|
| ✓ verified | A real encode succeeded and passed every check. Hover for the bytes and milliseconds. |
| ~ claimed | The library says it supports the format but the test failed. Hover for the reason. The format stays **disabled**. |
| ✗ | Not offered at all. |

A `~ claimed` cell is the one to act on. It means something is broken in a way
that would otherwise show up as corrupted images weeks later.

## Failure codes

| Code | Meaning | What to do |
|---|---|---|
| `not_claimed` | The library does not offer the format | Ask your host for a build that does |
| `encode_failed` | The encoding call returned failure | Usually a broken delegate; check the PHP error log |
| `wrong_format` | Output is not in the requested format | The library is misconfigured; do not enable it |
| `not_compressed` | Output was far larger than expected | The encoder is writing raw pixels; do not enable it |
| `decode_failed` | Output could not be read back | Encoder and decoder disagree; do not enable it |
| `not_found` | Binary is not installed | Ask your host for the package |
| `exec_unavailable` | PHP cannot run commands | Normal on shared hosting; library encoders still work |
| `managed_host_skip` | Binary discovery was skipped | Expected on WP Engine, Kinsta, Pantheon and similar |
| `no_reduction` | A lossless binary did not shrink the sample | Likely a wrapper script rather than the real tool |
| `tmp_unwritable` | Working directory is not writable | Check permissions on `wp-content/uploads` |

## Conversion outcomes

| Code | Meaning |
|---|---|
| `not_smaller` | The derivative was no smaller than the source. Normal for icons and optimized PNGs. Recorded as *skipped*, not an error. |
| `too_small` | Under the level's size threshold; not worth a second file. |
| `too_large` | The image would not fit in available memory. Raise `memory_limit` or accept the skip. |
| `too_wide` | Over WebP's 16,383-pixel limit. AVIF is still produced. |
| `animated` | Animated GIF or APNG. Converting would drop every frame but the first. |
| `unsupported_source` | Not a JPEG, PNG or GIF. |

## Common situations

**"AVIF is generated but never delivered."** Check the *MIME type for .avif* row
in the Environment table. If it says the server does not know `image/avif`, the
plugin needs to write an `AddType` line into `.htaccess`; if that file is not
writable, it disables AVIF delivery rather than serve something the browser will
pick and then fail to decode. Add `AddType image/avif .avif` to the server
configuration and re-run detection.

**"Nothing is being delivered at all."** Look for the conflict notice. While
another optimizer is active, delivery stays off by design; conversion keeps
running, so the derivatives are ready for when you switch over.

**"Thumbnail optimization is greyed out."** No lossless tool is installed for
that format. No PHP library can recompress a JPEG losslessly — that genuinely
requires `jpegoptim` or `jpegtran`. PNG works through GD when nothing better is
present.

**"Bulk conversion stalls."** WordPress cron is unreliable on many hosts, which
is why the bulk runner is browser-driven: leave the tab open and it keeps going.
If a worker was killed mid-batch, its rows unlock automatically after ten
minutes.

**"A gallery layout broke after enabling delivery."** Wrapping an `<img>` in a
`<picture>` breaks direct-child selectors like `.gallery > img`. Add the image's
class to the exclusion list on the Delivery tab, or switch to `.htaccess` mode,
which never touches the HTML. See [Delivery](delivery.md#layout-risk).

## Verifying originals were not touched

The guarantee is that source files stay byte-for-byte identical. To check it
yourself, take a manifest before and after a bulk run:

```bash
find wp-content/uploads -type f \( -name '*.jpg' -o -name '*.png' \) \
  -printf '%p %s %T@\n' | sort | sha256sum
```

The two hashes must match.

## Resetting

Re-run detection from the Diagnostics tab after any server change. To rebuild
every derivative, change the optimization level and run a bulk conversion: the
recorded quality per row means only files built at a different setting are
redone.

Deactivating the plugin removes nothing. Deletion removes nothing either, unless
*Uninstall* has been enabled on the Advanced tab.
