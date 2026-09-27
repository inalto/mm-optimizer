=== MM Optimizer ===
Contributors: inalto
Tags: images, optimization, webp, avif, performance
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Detects your server's graphics libraries, verifies which ones actually work, and proposes the optimal settings. Generates WebP and AVIF without ever touching the originals.

== Description ==

MM Optimizer starts from a question almost no image plugin asks: **what can this server actually do?**

Instead of presenting a wall of switches to guess at, it runs a full detection pass on activation and shows a capability matrix built from real tests. For every library and every format it encodes a known sample, checks the magic bytes, verifies the result can be read back, and measures how big it came out. Declared support is never taken on trust: that is precisely the case that produces corrupted files in silence.

The recommended configuration follows from that detection, with the reason shown next to every choice.

= What it does =

* **Detects** GD, Imagick, Gmagick and the system binaries (cwebp, avifenc, jpegoptim, jpegtran, optipng, pngquant, gifsicle).
* **Verifies** every encoder with a functional test, not a version check.
* **Ranks** the available engines by measuring them against the same test photograph and picks the one that produces the smallest file. No hand-written preference table: on a server where cwebp beats GD, cwebp wins.
* **Proposes** formats, quality, level and delivery mode, explaining each decision.
* **Generates** WebP and AVIF derivatives across four optimization levels.
* **Delivers** images responsively with `<picture>` and one `srcset` per format, or through `.htaccess` content negotiation.
* **Stands down** when another optimizer is active, instead of fighting it.

= What it deliberately does not do =

* **It never modifies your original files.** It writes sibling derivatives and touches only the thumbnails WordPress generates, at the moment they are created.
* No bundled binaries: it uses the system's, if any are present.
* No downloading executables at runtime.
* No lazy loading, CDN, telemetry or third-party gallery integrations.

= Optimization levels =

The numbers come from real measurements, not estimates.

* **Conservative** — WebP q90, AVIF q68. Highest quality.
* **Balanced** — WebP q82, AVIF q58. Around 45% smaller with WebP, 66% with AVIF.
* **Aggressive** — WebP q75, AVIF q50. Up to 78% smaller.
* **Custom** — your own values.

== Frequently Asked Questions ==

= Does it modify my original images? =

Never. Originals stay byte-for-byte identical. The plugin creates sibling files (`photo.jpg.webp`, `photo.jpg.avif`) and sets the quality WordPress uses when generating thumbnails, so they are born optimized without a second round of recompression.

= Can I run it alongside another optimizer? =

Conversion works regardless, but delivery stays off while the other plugin is active: two plugins rewriting the same URLs get in each other's way. The admin notice names the conflicting plugin.

= Why are some formats shown as "claimed" rather than "verified"? =

Because the library says it supports them but the encoding test failed. Those formats stay disabled on purpose: this is the situation that generates corrupted files with no visible error.

= AVIF is generated but not delivered. Why? =

Many servers have a MIME type list that predates AVIF and hand back `.avif` files as `application/octet-stream`. In `<picture>` mode the browser picks the AVIF source on the strength of the `type` attribute, then fails to decode it, and on Firefox and Safari does not fall back to the original image. The plugin checks this with a real request and adds an `AddType` line to `.htaccess`; if it cannot write one, it prefers not to deliver AVIF at all.

== Changelog ==

= 1.0.0 =
* First release.
