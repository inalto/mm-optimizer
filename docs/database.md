# Database

Two tables. One long-lived, one high-churn: mixing them would fragment the
long-lived table on every bulk run and force conflicting indexes.

## `{prefix}mm_optimizer_files`

One row per (source file, output format).

```sql
id            bigint unsigned NOT NULL AUTO_INCREMENT,
attachment_id bigint unsigned NOT NULL DEFAULT 0,
size_name     varchar(64)     NOT NULL DEFAULT '',
format        varchar(12)     NOT NULL DEFAULT '',
source_path   varchar(255)    NOT NULL DEFAULT '',
source_bytes  int unsigned    NOT NULL DEFAULT 0,
source_hash   char(32)        NOT NULL DEFAULT '',
out_bytes     int unsigned    NOT NULL DEFAULT 0,
status        tinyint         NOT NULL DEFAULT 0,
engine        varchar(16)     NOT NULL DEFAULT '',
quality       smallint        NOT NULL DEFAULT 0,
error_code    varchar(32)     NOT NULL DEFAULT '',
updated       int unsigned    NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY   source_format (source_path(191), format),
KEY          attachment (attachment_id, format),
KEY          status_updated (status, updated)
```

**`source_path`** is stored relativized — as `UPLOADS/2024/01/photo.jpg` rather
than an absolute path — and expanded on read. A site move or a changed
`UPLOADS` constant would otherwise orphan every row at once. It is the natural
key rather than `attachment_id` because the delivery layer frequently has a URL
and no ID.

**`source_hash`** is `md5( filesize . '|' . mtime )`, not a content hash.
Hashing a gigabyte of images on every check is not acceptable; one `stat()` call
is. It is the staleness test: if the hash differs, the derivative is rebuilt.

**`status`** uses four values: `0` pending, `1` done, `2` skipped, `3` error.
`2` is a **success** state, covering files where no worthwhile derivative was
possible. Without it the bulk runner would retry every incompressible thumbnail
on every pass, forever.

**`engine`** and **`quality`** record what actually produced each file, which
makes "the level changed, rebuild what was made at the old setting" a single
query and keeps the diagnostics honest.

**`error_code`** is a short symbolic code — `too_large`, `decode_failed`,
`not_smaller`, `animated`, `too_wide` — never a message, so the text stays
translatable at render time.

There is deliberately **no `out_path` column**. A derivative's path is a pure
function of the source path and the format; storing it would only let the two
drift apart.

## `{prefix}mm_optimizer_queue`

```sql
id            bigint unsigned NOT NULL AUTO_INCREMENT,
job_group     varchar(32)     NOT NULL DEFAULT '',
attachment_id bigint unsigned NOT NULL DEFAULT 0,
source_path   varchar(255)    NOT NULL DEFAULT '',
formats       varchar(32)     NOT NULL DEFAULT '',
priority      tinyint         NOT NULL DEFAULT 10,
attempts      tinyint         NOT NULL DEFAULT 0,
claimed_at    int unsigned    NOT NULL DEFAULT 0,
claim_token   char(16)        NOT NULL DEFAULT '',
created       int unsigned    NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
KEY          claim (claimed_at, priority, id),
KEY          grp (job_group)
```

Rows are deleted on success, so the queue stays small even with thousands of
attachments enqueued. `priority` is 5 for uploads and 10 for bulk work, so a new
upload jumps ahead of a backlog.

### The claim protocol

```sql
UPDATE …mm_optimizer_queue
   SET claimed_at = <now>, claim_token = '<16 random>', attempts = attempts + 1
 WHERE claimed_at = 0 OR claimed_at < <now - 600>
 ORDER BY priority ASC, id ASC LIMIT <batch>;

SELECT * FROM …mm_optimizer_queue WHERE claim_token = '<16 random>';
```

A worker killed mid-batch leaves its rows claimed; 600 seconds later they become
claimable again and `attempts` increments. At four attempts the row is dropped
and the failure is recorded in the tracking table instead.

**That is the entire stall recovery.** No watchdog process, no heartbeat, no
separate reaper.

## Locking

A `mm_opt_queue_lock` transient and a `queue.lock` file, both holding the same
random key, both required to start. A transient alone is not enough — an object
cache flush makes it vanish and two workers start together. A file alone is not
enough — it can be orphaned, so a file older than the TTL is treated as stale.
Release only happens when the stored key still matches, so a slow worker can
never delete a newer worker's lock.

## Options

| Option | Autoload | Contents |
|---|---|---|
| `mm_optimizer_settings` | yes | All settings, one serialized array |
| `mm_optimizer_capabilities` | **no** | Detection results |
| `mm_optimizer_bulk_state` | no | Bulk progress counters |
| `mm_optimizer_log` | no | Bounded diagnostic log |
| `mm_optimizer_worker_secret` | no | Loopback endpoint secret |
| `mm_optimizer_scan_offset` | no | Resume point for library scanning |
| `mm_optimizer_version` | yes | Installed version, drives schema upgrades |
