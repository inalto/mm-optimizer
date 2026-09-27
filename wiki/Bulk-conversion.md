# Bulk conversion

New uploads are converted automatically. Bulk conversion is for the library you
already have.

## Running it

**MM Optimizer → Bulk conversion** shows what the library holds, what has been
converted so far, and how much space has been saved. Click **Start conversion**.
If a queue is left over from an earlier run, the button reads **Resume (N
queued)** instead and carries on with that queue; **Rescan the library** throws
it away and scans from scratch.

The plugin scans the library in pages, queues what is missing, and works through
the queue in batches, reporting progress as it goes.

**Leave the tab open.** Conversion is driven from the browser, and that is the
primary route rather than a fallback: WordPress cron is unreliable on a great
many hosts, and a job of tens of thousands of encodes is exactly the kind that
a broken cron silently never finishes.

Closing the tab stops driving the run. Reopening the page and clicking
**Resume** picks up from where it left off — nothing already converted is redone.

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

A worker can die mid-batch — most often on the PHP time limit while encoding a
large original to AVIF. The plugin cleans up after it: the rows it held go back
in line, the lock is released and a new worker starts. The page retries a failed
batch on its own (the status line says *retrying*) and only gives up after about
three minutes of failures in a row.

If the process was killed outright, the lock is considered dead after two
minutes without a heartbeat. If the page says the queue is idle, click
**Resume**; there is nothing to reset by hand.

A file that kills the worker three times leaves the queue, and the log records
which one it was.

**Stop** clears the queue without touching anything already converted.
