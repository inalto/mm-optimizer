# FAQ

### Does it modify my original images?

No. Originals stay byte-for-byte identical. The plugin writes sibling files
(`photo.jpg.webp`, `photo.jpg.avif`) and never opens a source for writing.

You can verify it. Take a checksum before and after a bulk run:

```bash
find wp-content/uploads -type f \( -name '*.jpg' -o -name '*.png' \) \
  -printf '%p %s %T@\n' | sort | sha256sum
```

### Why are some formats shown as "claimed" rather than "verified"?

The library says it supports the format, but the plugin's encoding test failed.
Those formats stay disabled on purpose — this is exactly the situation that
produces corrupted images with no visible error.

### AVIF is generated but never delivered. Why?

Your server is probably returning `.avif` files with the wrong MIME type. See
[Delivering images](Delivering-images#the-avif-mime-type-trap).

### Can I run it alongside another optimizer?

Yes, though delivery stays off while one is active. See
[Migrating](Migrating).

### Do I need Imagick?

No. GD is enough, provided it was built with WebP and AVIF support — most modern
PHP 8.1+ builds are. The Diagnostics screen tells you exactly what you have.

### Why is thumbnail optimization greyed out?

No lossless tool is installed for that format. No PHP library can recompress a
JPEG without degrading it; that genuinely requires `jpegoptim` or `jpegtran`.
PNG works through GD when nothing better is available.

### Does it work with WooCommerce?

Yes. Product images and galleries go through the standard WordPress filters the
plugin hooks. Gallery zoom attributes are left untouched, so lightboxes keep
working.

### Does it work with a CDN?

`<picture>` mode does, and this is one of its main advantages: the HTML is
identical for every visitor, so caching behaves normally. `.htaccess` mode
requires `Vary: Accept`, which in practice disables CDN caching of images.

### What about page caching?

`<picture>` mode is cache-safe for the same reason.

### Will it slow down my site?

Conversion happens in the background, not during a page load. On the front end,
the plugin adds a small number of cached database lookups per page.

### Does it send anything anywhere?

No. There is no telemetry, no API, no account. The only outbound request is a
`HEAD` to your own site, to check how it serves an `.avif` file.

### Where are the converted files?

Next to the originals, with the format appended:
`2024/01/photo.jpg` → `2024/01/photo.jpg.webp`.

The extension is appended rather than replaced so `photo.jpg` and `photo.png` in
the same folder cannot collide.

### What happens when I delete an image?

Its derivatives are deleted with it, including when WordPress regenerates
thumbnails or you restore an edited original.

### What happens if I uninstall?

Nothing is removed, unless you first enable **Uninstall** on the Advanced tab.
Deactivating never removes anything.

### Can I use it on multisite?

The plugin runs per site. It has not been tested extensively on multisite; treat
that as unverified rather than supported.
