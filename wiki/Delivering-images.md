# Delivering images

Producing WebP and AVIF is half the job. The browser has to receive them.

## `<picture>` mode — the default

Each image becomes:

```html
<picture class="mm-opt-picture">
  <source type="image/avif" srcset="…">
  <source type="image/webp" srcset="…">
  <img …your original tag, untouched…>
</picture>
```

The browser takes the first format it understands and falls back to the original
`<img>` otherwise. Every attribute on that `<img>` is preserved exactly —
`loading`, `decoding`, `class`, every `data-*`, the original `srcset` and
`sizes`. If anything about the sources is wrong, the untouched `<img>` still
works.

**It is fully responsive.** Each `<source>` carries its own `srcset` built from
the sizes WordPress already calculated, so the browser picks both the best
format *and* the right size for the screen.

**It is cache-safe.** The HTML is identical for every visitor, so page caches
and CDNs work normally.

### Why a source sometimes does not appear

If any size in the srcset is missing its derivative, that whole `<source>` is
left out rather than shipped incomplete.

This is deliberate. A partial srcset gives the browser a shorter ladder, so it
picks a *lower* resolution than it would have from the `<img>` — images look
worse, and the cause is nearly impossible to find. Run bulk conversion to fill
the gaps and the source appears.

## The AVIF MIME-type trap

If AVIF is being generated but never delivered, this is almost always why.

`<source type="image/avif">` is a promise the browser believes *before* it
fetches anything. It picks that source, requests the file, and if your server
returns `application/octet-stream` instead of `image/avif`, Firefox and Safari
fail — and do **not** fall back to the `<img>`. Chrome guesses from the content
and survives, which makes this easy to miss.

Many servers have a MIME type list older than AVIF. The plugin checks yours with
a real request, and writes an `AddType image/avif .avif` line into `.htaccess`
to fix it. If `.htaccess` is not writable, it switches AVIF delivery off rather
than serve something half your visitors cannot display, and tells you on the
Diagnostics screen.

To fix it at the server level, add this to your Apache configuration:

```apache
AddType image/avif .avif
AddType image/webp .webp
```

Then re-run detection.

## `.htaccess` mode

Apache serves the WebP or AVIF file at the original URL based on the browser's
`Accept` header. Your HTML is never touched, which avoids every layout concern
below.

The cost is caching: correct negotiation needs `Vary: Accept` on every image,
which in practice stops CDNs caching images. That is why `<picture>` is the
default.

## If a layout breaks

Wrapping an `<img>` adds an element to the page. The plugin ships a
`display: contents` rule that removes it from layout, so flexbox, grid and
`object-fit` keep working.

What that cannot fix is CSS written as `.gallery > img` — a *direct child*
selector, which stops matching once anything sits between the two.
`.gallery img` is unaffected.

Two ways out:

1. Add the image's CSS class to **Delivery → CSS classes to exclude**.
2. Switch to `.htaccess` mode, which never touches the HTML.

Check galleries, sliders and product images after enabling delivery. Those are
where custom CSS tends to live.

## When delivery is switched off

If another optimization plugin is active, delivery stays off deliberately — see
[Migrating](Migrating). Conversion carries on, so everything is ready the moment
you deactivate the other plugin.
