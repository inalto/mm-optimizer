# Bulk conversion

New uploads are converted automatically. Bulk conversion is for the library you
already have.

## Running it

**MM Optimizer → Bulk conversion** shows what the library holds, what has been
converted so far, and how much space has been saved. Click **Start conversion**.

The plugin scans the library in pages, queues what is missing, and works through
the queue in batches, reporting progress as it goes.

**Leave the tab open.** Conversion is driven from the browser, and that is the
primary route rather than a fallback: WordPress cron is unreliable on a great
many hosts, and a job of tens of thousands of encodes is exactly the kind that
a broken cron silently never finishes.

Closing the tab stops the run. Reopening and starting again resumes from where
it left off — nothing already converted is redone.

## How long it takes

Roughly half a second per derivative for a 2048 px image, on typical shared
hosting. For a library of a few thousand images across several sizes and two
formats, expect hours rather than minutes.

It is entirely safe to run it in stages across several sittings.

## What the counters mean

| Counter | Meaning |
|---|---|
| **Derivatives generated** | Files successfully created |
| **Skipped (no gain)** | No worthwhile derivative was possible — normal for icons, logos and already-optimized PNGs |
| **Errors** | Something genuinely went wrong; check the Diagnostics screen |
| **Space saved** | Difference between sources and derivatives |
| **Queued** | Still to do |

A high *skipped* count is not a problem. Small graphics frequently encode larger
as WebP or AVIF than they already are, and writing a bigger file to replace a
smaller one would be pointless.

## Which sizes get converted

By default, only sizes that actually appear in a `srcset`, plus the larger
cropped ones.

A typical site registers a dozen or more image sizes once the theme, WooCommerce
and core have all had their say. Converting every one into two formats multiplies
both the work and the disk space, often for files no page ever requests. The
Advanced tab can switch to converting everything if you want that.

## Disk space

Every image gains up to two extra files. A 1 GB uploads folder might add several
hundred megabytes. The saving is in what visitors download, not what you store.

## If it stalls

The progress line stops updating if a worker was interrupted. Rows unlock
automatically after ten minutes and the next run picks them up; there is nothing
to reset by hand.

**Stop** clears the queue without touching anything already converted.
