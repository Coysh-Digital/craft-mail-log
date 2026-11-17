# Mail Log for Craft CMS 5

A focused Craft CMS 5 plugin that logs every email sent via Craft's mailer,
regardless of the transport adapter in use (Sendmail, SMTP, Gmail, SES, etc.).

## What it does

- Captures every outbound email from Craft's mailer and records:
  - Envelope data: subject, from, reply-to, to, cc, bcc
  - Text and HTML bodies (optional)
  - Attachment filenames
  - Success/failure status and any error reported
  - Transport class in effect at send time
  - Craft system message key (e.g. `account_activation`) when applicable
- CP view to search, filter, inspect and delete log entries
- HTML preview that blocks remote images and other external content by
  default, so viewing an entry never fires tracking pixels
- Redaction of system emails containing single-use links (password reset,
  account activation, email verification)
- Settings for body logging, body compression, failure logging, redaction,
  retention and CP nav label
- Entries are kept for **30 days** by default, with cleanup during Craft's
  garbage collection and a `php craft mail-log/logs/prune` console command
  (with `--dry-run`) for scheduled, predictable cleanup
- Stored bodies are compressed by default, typically to a quarter of their
  size or less

## How it works

The plugin hooks `craft\mail\Mailer::EVENT_AFTER_SEND`. This event is defined
on Yii's `BaseMailer` and fired inside its `send()` method, so it is
transport-agnostic — it will fire no matter which adapter the site has
configured.

Failed sends need extra care: when a transport fails it throws, so
`EVENT_AFTER_SEND` never fires, and Craft's mailer catches the exception and
returns `false` without raising any event. To capture these, the plugin wraps
the mailer's Symfony transport in a transparent decorator
(`FailureReportingTransport`) during `EVENT_BEFORE_SEND`. The decorator logs
the failure, including the transport's error message, and then re-throws the
original exception unchanged, so Craft and any other plugins behave exactly as
before.

Compared to the approach of replacing Craft's mailer class wholesale
(as some plugins do), this keeps Mail Log composable: it won't conflict
with other plugins that extend or replace the mailer, and they in turn
will still fire the after-send event and be logged.

## Installation

```
composer require coyshdigital/craft-mail-log
./craft plugin/install mail-log
```

Requires Craft CMS 5.0+ and PHP 8.2+.

## Settings

Found under **Settings → Plugins → Mail Log**:

| Setting | Default | Effect |
|---|---|---|
| Log email bodies | On | Persist text and HTML bodies to the database. Bodies make up almost all of the log's storage (see [Storage and retention](#storage-and-retention)) |
| Compress email bodies | On | Deflate-compress stored bodies. Decompressed automatically when viewed; entries stored uncompressed stay readable |
| Log failed sends | On | Create an entry when a send fails |
| Redact sensitive emails | On | Don't store or display bodies of system emails that contain single-use links |
| Retention (days) | 30 | How many days to keep entries (0 = forever); applied during garbage collection and by the `prune` console command |
| CP nav label | empty | Override the sidebar label |

### Sensitive emails

Craft's password reset, account activation and email verification emails
contain links that let whoever holds them sign in as the recipient. Without
redaction, any CP user with access to the log could trigger a password reset
for an admin account and read the link from the log. With **Redact sensitive
emails** on (the default), the bodies of these messages are not stored, and
bodies already stored are hidden in the CP. The envelope (recipient, subject,
status) is still logged.

The list of redacted system message keys defaults to `account_activation`,
`forgot_password` and `verify_new_email`. To cover messages registered by
other plugins, extend it in `config/mail-log.php`:

```php
<?php

return [
    'sensitiveSystemKeys' => [
        'account_activation',
        'forgot_password',
        'verify_new_email',
        'my_plugin_magic_link',
    ],
];
```

## Permissions

- `accessPlugin-mail-log` (auto-registered) — view the log
- `mailLog:delete` — delete individual entries or flush all

## Storage and retention

Each logged email is one row, and the bodies are most of its size. A typical
HTML email is **around 50 KB** uncompressed, so unlimited retention on a busy
site adds up quickly: 100,000 emails is about 5 GB before compression.

- **Retention defaults to 30 days.** That's enough to investigate "did this
  email go out?" questions while keeping the table small. Set
  **Retention (days)** to `0` to keep entries forever. The Control Panel warns
  when you do that with body logging on.
- **Bodies are compressed by default**, which usually shrinks them to a
  quarter of their size or less. Compression is applied when an email is
  logged; older entries are left as they are and remain readable. The only
  trade-off is that bodies can't be read directly with SQL or searched in a
  database dump. Turn **Compress email bodies** off if you need that.
- **If you don't need bodies, turn off Log email bodies.** The envelope
  (recipients, subject, status, errors) is a few hundred bytes per entry.

### Upgrading from 1.x

Retention used to default to `0` (forever). Craft only stores a plugin
setting once someone has saved the settings form, so:

- If you have **never saved** Mail Log's settings, you now get the 30-day
  default, and entries older than that are removed the next time cleanup runs.
- If you **have saved** them, your stored value (including `0`) is kept.
- A value set in `config/mail-log.php` always wins.

To keep everything, set `retentionDays` to `0`. To avoid depending on defaults,
set it explicitly:

```php
<?php
// config/mail-log.php
return [
    'retentionDays' => 30,
];
```

### Recommended production setup

1. Set `retentionDays` explicitly in `config/mail-log.php`, as above.
2. Run the prune command from cron so retention doesn't depend on chance:

   ```
   15 3 * * * cd /path/to/project && php craft mail-log/logs/prune
   ```

Craft's garbage collection also prunes expired entries, but by default it only
runs on about 1 in 100,000 requests (the `probability` of the `gc` component in
`config/app.php`), and Mail Log caps each run at 5,000 entries so the request
that triggers it is never held up. It's a safety net, not a schedule. (`php
craft gc` also runs it on demand, along with all of Craft's other cleanup.) If your site has a big backlog (say, from running
unlimited retention for a long time), use the console command.

## Console

```bash
# Delete entries older than the configured retentionDays (default 30)
php craft mail-log/logs/prune

# Report what would be deleted without deleting anything
php craft mail-log/logs/prune --dry-run

# ...or use a specific number of days
php craft mail-log/logs/prune --days=14

# Smaller or larger batches (default 500, between 1 and 10,000)
php craft mail-log/logs/prune --batch-size=250

# Delete every entry (interactive confirm; empties the table instantly)
php craft mail-log/logs/flush
```

`prune` prints the retention period and where it came from, the cutoff (in UTC
and in the system time zone), how many entries exist and how many are
eligible, progress after each batch, and the time taken.

- Entries are deleted in small batches, each in its own transaction, so locks
  are short and outgoing email is never held up. It is safe to interrupt with
  Ctrl+C and to run again: it continues with what is left.
- If the database fails mid-run, the command reports how many entries were
  already deleted and exits with an error. Running it again is safe.
- An explicit `--days` below 7 asks for confirmation (pass `--interactive=0`
  to skip prompts in scripts), so a typo can't wipe out recent history.
- `--days=0` and a `retentionDays` of `0` do nothing.

### Reclaiming disk space after a large prune

Deleting rows frees space *inside* the database, but MySQL/InnoDB doesn't give
it back to the operating system: the table file stays the same size until the
table is rebuilt. If a prune is meant to relieve a full disk, plan for these
before running it on a large table:

- **Binary logs.** With binary logging on (the MySQL 8 default), deleting rows
  writes them to the binary log too, so pruning several gigabytes can *use*
  roughly that much disk before it frees any. Check `SHOW VARIABLES LIKE
  'log_bin'` and the free space on the data volume first.
- **Rebuilding the table** (`OPTIMIZE TABLE`, or copying the rows you want to
  keep into a new table and swapping it in) needs temporary space about the
  size of the data that remains, and can briefly lock the table. Check free
  space against that figure before starting.
- Once new entries are compressed and retention is on, the table stays small,
  so this is a one-off for sites with an existing backlog.

## Data model

A single table `{{%mail_log_entries}}`. Address columns are stored as JSON
and decoded on read.

## Known limitations

- Failure capture relies on reading the transport from
  `yii\symfonymailer\Mailer`, which keeps it in a private property. If a
  future version of that library changes this, failures will stop being
  logged (a warning is written to Craft's log) but successful sends will
  still be logged.
- Failures that happen before the transport is called (for example in a
  message signer or encrypter) are not logged.
- Body storage can grow quickly on high-volume sites. Keep `retentionDays` set
  (the default is 30), and schedule `mail-log/logs/prune`. See
  [Storage and retention](#storage-and-retention).
- Attachments are recorded by filename only, not contents.

## Development

```bash
composer install
composer test     # PHPUnit tests
composer phpstan  # static analysis
```

The tests that need a database run against in-memory SQLite by default. To run
them against MySQL or PostgreSQL, point them at a throwaway database (its
`mail_log_entries` table is dropped and recreated for every test):

```bash
MAIL_LOG_TEST_DSN='mysql:host=127.0.0.1;dbname=maillog_test' \
MAIL_LOG_TEST_USER=root MAIL_LOG_TEST_PASSWORD=secret composer test
```

## License

MIT
