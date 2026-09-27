# Delivery

Producing derivatives is only half the job; the browser has to receive them.
Two modes are available, and `<picture>` is the default for a specific reason.

## `<picture>` mode

Each eligible `<img>` is wrapped:

```html
<picture class="mm-opt-picture">
  <source type="image/avif" srcset="…" sizes="…">
  <source type="image/webp" srcset="…" sizes="…">
  <img …the original tag, byte-identical…>
</picture>
```

The parsing uses `WP_HTML_Tag_Processor`, never a regular expression. Two
reasons: it round-trips unknown attributes exactly, which matters on sites where
page builders inject `data-*` heavily, and it gives a token stream so images
already inside a `<picture>` can be skipped.

**The inner `<img>` comes back verbatim.** Every attribute survives — `loading`,
`decoding`, `fetchpriority`, `class`, all `data-*`, the original `srcset` and
`sizes`. This is the single most important correctness property: if the
`<source>` elements are wrong in any way, the browser still has an untouched
`<img>` to fall back to.

### Per-format srcset

The srcset is not recomputed from the registered image sizes. It is taken from
what core already calculated and mapped one-to-one, because
`wp_calculate_image_srcset()` has already done the aspect-ratio filtering, the
width cap, the relative-path resolution and the SSL normalisation.
Reimplementing that is how a plugin diverges from core on the next release.

`sizes` is copied verbatim onto every `<source>`, including WordPress 6.7+'s
`sizes="auto, …"`, which is valid on `<source>` too.

### The all-or-nothing rule

If any candidate in the srcset lacks a derivative, that whole `<source>` is
omitted.

This looks over-cautious and is not. A partial srcset gives the browser a
shorter ladder to choose from, so it picks a *lower* resolution than it would
have taken from the `<img>` — a visible quality regression that is nearly
impossible to trace back to its cause. Shipping no AVIF source is strictly
better than shipping an incomplete one.

`delivery.partial_srcset` opts into the subset behaviour. It is off by default.

## The AVIF MIME-type trap

`<source type="image/avif">` is a hint the browser trusts **before** fetching.
It selects that source, requests the file, and if the server returns
`application/octet-stream`, Firefox and Safari fail — **with no fallback to the
`<img>`**. Chrome sniffs the content and survives, which makes this easy to miss
in testing.

Plenty of servers have a MIME type list that predates AVIF. On the host this
plugin was developed against, `/etc/mime.types` is dated 2020 and has
`image/webp` but no `image/avif` line at all.

So the plugin:

1. probes it empirically, with an HTTP HEAD request against a bundled
   `probe.avif`, rather than assuming;
2. writes `AddType image/avif .avif` into its `.htaccess` marker block **even in
   `<picture>` mode**, where no rewriting is otherwise needed;
3. if the probe still reports the wrong type and `.htaccess` is not writable,
   sets `avif.deliver = 0` and says why. Delivering a source the browser will
   choose and then fail to decode is worse than not delivering it.

## What gets skipped

| Case | Why |
|---|---|
| Already inside a `<picture>` | Never wrap twice. |
| `src` is a data URI or external | Nothing to map to. |
| A lazy loader owns the element | `data-src` and friends hold the real URL; the `src` is a placeholder, and building sources from it would point the `<picture>` at the placeholder. |
| Class is in the exclusion list | Escape hatch for layouts that break. |
| Admin, AJAX, cron, REST, XML-RPC, AMP | Not a public page request. AMP forbids `<picture>` outright. |

## Layout risk

Wrapping an `<img>` inserts an element into the box tree. The plugin ships one
rule to deal with it:

```css
picture.mm-opt-picture { display: contents }
```

`display: contents` removes the element from layout entirely, so existing flex
rules, grid placement and `object-fit` keep working unchanged. An
`@supports not (display: contents)` fallback covers older browsers.

What this **cannot** fix is direct-child selectors. A stylesheet written as
`.gallery > img` stops matching once anything is interposed, because
`display: contents` affects layout, not the DOM. Descendant selectors
(`.gallery img`) are unaffected. If a theme relies on child combinators, either
add the class to the exclusion list or use `.htaccess` mode, which never touches
the HTML.

## `.htaccess` mode

Apache rewrites based on the `Accept` header, serving `photo.jpg.webp` at the
`photo.jpg` URL. It never touches the HTML, which sidesteps every layout concern
above.

The cost is caching. Correct `Accept` negotiation requires `Vary: Accept` on
every image response, which in practice disables CDN caching for images — the
same trade every plugin using this approach makes. `<picture>` mode is
cache-safe by construction: the HTML is byte-identical for every visitor and the
negotiation happens in the browser.

The plugin refuses to write its rules if another plugin's markers are already
present in the same file, and removes its own block on deactivation.
