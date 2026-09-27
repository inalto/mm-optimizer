# Optimization levels

Four presets rather than a row of sliders you have to guess at. The numbers come
from encoding a real 2048×1367 photograph of 721,366 bytes.

| Level | WebP | AVIF | Typical saving |
|---|---|---|---|
| **Conservative** | q90 | q68 | ~28% / ~53% |
| **Balanced** *(recommended)* | q82 | q58 | **~45% / ~66%** |
| **Aggressive** | q75 | q50 | ~59% / ~78% |
| **Custom** | yours | yours | — |

## Which to choose

**Balanced** unless you have a reason not to. It is the setting where a
photograph is indistinguishable from the original at normal viewing sizes while
weighing a third of what it did.

**Conservative** for photography and portfolio sites where the images are the
product, and where a client may pixel-peep. You give up roughly a third of the
possible saving.

**Aggressive** when you have thousands of images and page weight is the problem
you are actually solving. Fine texture and smooth gradients can show artefacts
at AVIF q50; flat colour and photographic subjects hold up well.

**Custom** once you have used the levels enough to know what you want to change.

## Things that happen at every level

**A derivative that is not smaller is deleted.** Icons, logos and
already-optimized PNGs frequently encode larger than the original. Those are
recorded as *skipped* — a normal outcome, not an error — and are not retried on
every pass.

**Images too large for memory are skipped, not attempted.** A 34-megapixel
original needs around 136 MB just to hold the pixels. Rather than let that kill
the request, it is recorded and passed over.

**Animated GIFs and APNGs are left alone**, because converting them would drop
every frame but the first.

**Transparency is preserved.** Both WebP and AVIF keep the alpha channel.

## About your thumbnails

The plugin sets the quality WordPress uses when it *creates* thumbnails, so they
are born optimized. It does not recompress existing thumbnails in place, which
would mean a second round of loss on an already-lossy file.

For images already in your library, bulk conversion works from the untouched
original. That gives better quality than recompression — and it is only possible
because originals are never modified.

Separately, if your server has a genuine lossless tool (`jpegoptim`, `jpegtran`,
`optipng`, `gifsicle`), the Advanced tab can enable in-place lossless
optimization of thumbnails. Where no such tool exists the option is greyed out
and the Diagnostics screen names the package to request. No PHP library can
losslessly recompress a JPEG, so the plugin does not pretend otherwise.

## Changing level later

Changing the level and re-running bulk conversion rebuilds only what was
produced at a different setting. Each row records the quality it was built with,
so nothing is redone unnecessarily.
