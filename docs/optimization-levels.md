# Optimization levels

Four discrete presets rather than a row of sliders. The numbers are not
guesses: they come from encoding a real 2048×1367 photograph of 721,366 bytes.

## The measurements

| Encode | Output | Saving |
|---|---|---|
| WebP q82 | 400,174 B | −44.5% |
| WebP q75 | 293,764 B | −59.3% |
| AVIF q60 | 261,654 B | −63.7% |
| AVIF q50 | 161,662 B | −77.6% |

Two things follow. AVIF is both smaller and faster than WebP on photographic
content, which is why it comes first in the `<picture>` source order. And the
gap between them — roughly 40% at comparable visual quality — is large enough
that AVIF is worth chasing even when WebP is already in place.

## The presets

| | Conservative | **Balanced** (default) | Aggressive | Custom |
|---|---|---|---|---|
| WebP from JPEG | q90 | **q82** | q75 | free |
| WebP from PNG | lossless | q85 | q80 | free |
| AVIF from JPEG | q68 | **q58** | q50 | free |
| AVIF from PNG | q75 | q65 | q55 | free |
| AVIF speed | 4 | **6** | 8 | 0–10 |
| cwebp `-m` | 6 | **6** | 4 | 0–6 |
| Minimum saving to keep | 5% | **8%** | 10% | 0–50% |
| Skip sources under | 4 KB | **10 KB** | 10 KB | free |

### A note on `cwebp -m`

GD always encodes with libwebp method 4 and offers no way to change it — that is
why GD and `cwebp -q 82` produce byte-identical output at default settings.
`cwebp -m 6` costs roughly twice the time and returns 5–7% smaller files, so the
quality-oriented levels use it. Aggressive drops back to 4 because it is the
level you pick when clearing a backlog of thousands of images.

### A note on AVIF speed

The `speed` parameter trades encoding time against file size. A 7140×4765
original encodes in 4.83 s at speed 6. Speed 8 roughly halves that for about
8–12% more bytes, which is the right trade when the job is large; speed 4 is the
right trade when quality leads.

## Safety rules, applied at every level

**Discard if bigger.** A derivative that exceeds its source is deleted and
recorded as *skipped*, not *failed*. This happens constantly with icons and
already-optimized PNGs. Recording it as a success state matters: otherwise the
bulk runner retries the same incompressible files forever.

**Minimum saving.** A derivative has to beat the source by the preset's margin,
not merely tie it. Writing a second file to save 2% is not worth the disk space
or the extra HTTP negotiation.

**Memory ceiling.** Before opening anything, the pixel count is checked against
the available memory: GD holds uncompressed pixels, four bytes per pixel plus a
working copy. A 7140×4765 image needs about 136 MB for a single buffer. Sources
that would not fit are recorded as `too_large` and skipped, rather than being
allowed to kill the request with a fatal error.

**Format limits.** WebP cannot exceed 16,383 pixels on a side. Sources past that
are skipped for WebP and still converted to AVIF.

**Animation.** Animated GIFs and APNGs are detected from their internal
structure — not their extension — and skipped entirely, since converting them
would drop every frame but the first.

**Alpha.** Palette PNGs are promoted to true colour before encoding, otherwise
WebP and AVIF lose the transparency.

## Thumbnails, and why originals stay safe

"Optimize thumbnails" is implemented at *generation* time, not by recompressing
files afterwards. The plugin sets the quality WordPress uses when it creates
sub-sizes, so each thumbnail is produced once, from the original, at the chosen
quality. There is no second lossy pass, and the original is never opened for
writing.

For images already on disk, the bulk tool regenerates from the untouched
original rather than recompressing in place. That is strictly better quality
than recompression — and it is only possible *because* originals were never
touched.

Lossless in-place optimization is separate, and only runs where a genuine
lossless tool exists: `jpegoptim` or `jpegtran` for JPEG, `optipng` or GD for
PNG, `gifsicle` for GIF. No PHP library can recompress a JPEG without degrading
it, so on a host without those binaries the plugin disables the feature and
names the package to ask for, rather than pretending.
