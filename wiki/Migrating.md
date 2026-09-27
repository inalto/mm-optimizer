# Migrating from another plugin

MM Optimizer detects other image optimizers and stands down rather than fighting
them.

## What happens while another plugin is active

| Still running | Switched off |
|---|---|
| Conversion of new uploads | `<picture>` delivery |
| Bulk conversion | `.htaccess` rules |
| Diagnostics and recommendations | |
| Migration tools | |

Conversion keeps running on purpose: the derivatives get built while the other
plugin is still serving, so switching over is instant rather than a wait.

An admin notice names the plugin it found. Detected: EWWW Image Optimizer,
Converter for Media, Imagify, ShortPixel, Smush and Optimole — including renamed
folders, and deactivated plugins that left rewrite rules behind in `.htaccess`.

## Coming from Converter for Media

The Bulk conversion tab gains an import section.

**Import the settings** reads the other plugin's configuration and maps it onto
the nearest optimization level. It maps to a level rather than copying the raw
quality number, because the levels are calibrated against real measurements and
a single inherited number is not.

**Adopt the converted files** takes the WebP and AVIF files already in
`wp-content/uploads-webpc/` and moves them into place, turning a multi-hour
re-encode into a directory scan.

### Adopt or regenerate?

**Adopt** if you want to switch over now. It takes minutes.

**Regenerate** if you can spare the time, and especially if the inventory is
mostly WebP. Converter for Media installations in the wild rarely have much
AVIF, and AVIF is where the remaining gain is — roughly 20% beyond WebP on
photographic content. The screen tells you when this applies to your library.

You can adopt first and regenerate later; adopted files are replaced as the
bulk run reaches them.

## Switching over

1. Install and activate MM Optimizer alongside the existing plugin.
2. Open **Diagnostics** and apply the recommended configuration.
3. Run **Bulk conversion** to completion. Delivery is still off at this point.
4. Deactivate the other plugin.
5. Remove its rewrite rules if it left any — check `.htaccess` in your site
   root, `wp-content/` and `wp-content/uploads/` for its marker block.
6. Reload the MM Optimizer settings. Delivery arms itself automatically.
7. Load a page and confirm images are being served as AVIF or WebP in your
   browser's network panel.

Nothing is deleted from the other plugin. If you want to go back, reactivate it.

## Coming from EWWW

EWWW writes its WebP files next to the originals, using the same naming scheme
this plugin uses. Existing files are picked up as they are, so there is no
adoption step.

One extra note: while EWWW is active, in-place thumbnail optimization stays off
here too. Both plugins would be rewriting the same files, which is a genuine
risk of corruption rather than a theoretical one.
