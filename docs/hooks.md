# Hooks and API

## Filters

### `mm_optimizer_quality`

Overrides the encoding quality for a single conversion.

```php
add_filter( 'mm_optimizer_quality', function ( $quality, $source_mime, $target_mime ) {
    // Push AVIF harder on PNG sources only.
    if ( 'image/png' === $source_mime && 'image/avif' === $target_mime ) {
        return 70;
    }
    return $quality;
}, 10, 3 );
```

### `mm_optimizer_blocks`

Overrides the stand-down decision for one subsystem. Receives `'picture'`,
`'htaccess'` or `'thumbnails'`.

```php
// Keep <picture> delivery on despite a detected conflict.
add_filter( 'mm_optimizer_blocks', function ( $blocked, $subsystem ) {
    return 'picture' === $subsystem ? false : $blocked;
}, 10, 2 );
```

### `mm_optimizer_encoder_for`

Forces a specific engine for an output format, bypassing the measured ranking.

```php
add_filter( 'mm_optimizer_encoder_for', function ( $engine, $target_mime ) {
    return 'image/webp' === $target_mime ? 'gd' : $engine;
}, 10, 2 );
```

### `mm_optimizer_binary_paths`

Adds directories to the binary search. Paths are still filtered through
`open_basedir` and still have to pass the functional test.

```php
add_filter( 'mm_optimizer_binary_paths', function ( $paths ) {
    $paths[] = '/opt/imaging/bin';
    return $paths;
} );
```

### `mm_optimizer_webpc_dir`

Points the migrator at a non-default Converter for Media output directory.

## Public functions

All in the global namespace.

| Function | Returns |
|---|---|
| `mm_optimizer_get_option( $path, $default = null )` | A setting, using dot notation: `mm_optimizer_get_option( 'avif.enabled' )` |
| `mm_optimizer_capabilities( $force = false )` | The full capability array |
| `mm_optimizer_recommendation()` | Recommended settings, reasons and blockers |
| `mm_optimizer_supports( $format )` | Whether `'webp'` or `'avif'` can genuinely be produced |

## WordPress hooks registered

### Conversion

| Hook | Priority | Purpose |
|---|---|---|
| `wp_generate_attachment_metadata` | 20 | Queue an upload's derivatives. Runs after core and after plugins that add sizes. Returns the metadata unmodified. |
| `wp_update_attachment_metadata` | 20 | Catches programmatic writes that skip the above. Debounced per request. |
| `wp_editor_set_quality` | 10 | Thumbnail quality, per MIME type |
| `jpeg_quality` | 10 | JPEG thumbnail quality. Leaves `edit_image` alone, since that saves an edited original rather than a thumbnail. |

### Deletion

| Hook | Priority | Purpose |
|---|---|---|
| `delete_attachment` | 10 | Remove derivatives and rows while the metadata is still readable |
| `wp_delete_file` | 10 | Catch-all: any file core deletes takes its derivatives with it. Covers thumbnail regeneration and "restore original". |

### Delivery

Registered only on a public page request, and only when no conflict is active.

| Hook | Priority | Purpose |
|---|---|---|
| `wp_content_img_tag` | 20 | Images in post content |
| `wp_get_attachment_image` | 20 | Featured images, blocks, WooCommerce galleries |
| `wp_calculate_image_srcset` | 20 | **Records only, never mutates** |
| `wp_head` | 1 | The `display: contents` rule |

### Queue

`cron_schedules` adds a five-minute interval; `mm_optimizer_run_queue` is the
worker action; `wp_ajax_mm_optimizer_worker` and its `nopriv` twin are the
loopback endpoint, authenticated by a rotating secret compared with
`hash_equals()` — a nonce would be meaningless on an endpoint reachable without
a session.

## Hooks deliberately not used

`big_image_size_threshold` is left alone. Some plugins force it to `false` and
take over scaling the original; since MM Optimizer never modifies originals,
there is nothing to take over.

`wp_image_editors` is not filtered. The usual approach subclasses the image
editors to optimize each thumbnail as it is written, which needs a global to
prevent recursion. The same result comes from `wp_editor_set_quality` without
the fragility.

`image_editor_output_format` is not filtered either: it makes core *replace* the
source format rather than add to it, which would mean modifying originals.
