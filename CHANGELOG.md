# Mail Log Changelog

## 2.0.0 - 2026-11-17

### Changed

- **Retention now defaults to 30 days** (it was 0, meaning forever), so the log no longer grows without limit. Installs that have saved Mail Log's settings keep their stored value, including `0`. Installs that never saved them now get 30 days, and entries older than that are removed the next time cleanup runs. To keep everything, set `retentionDays` to `0`.
- Stored email bodies are now compressed, typically to a quarter of their size or less. This applies to new entries only; existing entries remain readable. Turn off the new **Compress email bodies** setting (`compressBodies`) to store bodies as plain text.
- Garbage collection now removes at most 5,000 expired entries per run, so a large backlog can't stall the request that triggers it. Run `mail-log/logs/prune` for predictable cleanup.
- Pruning walks the table by primary key in batches of 500 (it was 1,000, re-scanning from the start each time), each in its own transaction.
- **Delete all** and `mail-log/logs/flush` now empty the table with `TRUNCATE` rather than one huge `DELETE`, which is instant and returns the disk space. If the database user can't truncate, they fall back to batched deletes.

### Added

- `mail-log/logs/prune --dry-run` reports how many entries would be deleted without deleting them.
- `mail-log/logs/prune --batch-size` (default 500).
- `prune` now shows the retention period and its source, the cutoff in UTC and local time, the number of entries and how many are eligible, progress per batch, and elapsed time. It reports a database failure with how many entries were already deleted, and is safe to re-run.
- An explicit `--days` below 7 asks for confirmation, and a negative `--days` is rejected.
- A warning in the Control Panel (on the log list and in the settings) when entries are kept forever while bodies are stored.
- Tests covering retention, cutoff calculation, pruning in batches, garbage collection, the console command, logging, redaction and body compression, which run against SQLite or, optionally, MySQL.

### Fixed

- The retention cutoff was computed in the system time zone, so across a daylight saving change it could be an hour out, deleting entries up to an hour too early or keeping them up to an hour too long. It is now exactly the requested number of 24-hour days.
- PHPStan configuration referred to a stub file that current Craft releases no longer ship; it now uses `craftcms/phpstan`.

## 1.1.0 - 2026-10-25

### Added

- Search and status filtering on the log list.
- New **Redact sensitive emails** setting (on by default). Bodies of Craft's password reset, account activation and email verification emails are no longer stored, and bodies stored before this release are hidden in the CP. The list of keys can be extended with the `sensitiveSystemKeys` config setting.
- The HTML preview now blocks remote images and other external content by default, so viewing an entry can't fire tracking pixels or reveal the viewer's IP address. A "Load remote content" link loads them on request.
- Expired entries are now pruned during Craft's garbage collection when `retentionDays` is set, so a cron job is no longer required.
- `--days` option for `mail-log/logs/prune`.
- PHPUnit tests and PHPStan configuration.

### Fixed

- Failed sends were never logged. Craft's mailer catches transport exceptions before `EVENT_AFTER_SEND` fires, so the "Log failed sends" setting had no effect. Failures are now captured, together with the transport's error message.
- The transport class was always recorded as empty, because the mailer's transport isn't publicly accessible.
- Emails with subjects longer than 500 characters (or overlong sender names) failed to log at all; they are now truncated.
- Retention pruning compared UTC timestamps against local time, so entries were deleted hours early or late depending on the system time zone.
- HTML or text bodies set as streams weren't stored.
- Deleting an entry from its detail page redirected back to the deleted entry. It now returns to the list, and deleting from the list keeps the current page and filters.
- Very long subjects no longer stretch the page layout.
- Recipient, CC/BCC, reply-to and attachment lists weren't decoded correctly when displayed in the CP.

### Changed

- The log list no longer loads email bodies, which makes it much faster on large logs.
- Pruning deletes in batches of 1,000 to avoid long table locks.
- Empty CC/BCC/Reply-to lists and attachment lists are stored as `NULL` rather than `[]`.
- Accessibility: labelled search controls, descriptive delete buttons and a title on the preview iframe.

## 1.0.1 - 2026-04-24

### Fixed

- The To field could be empty; recipients are now read from the underlying Symfony email.

## 1.0.0 - 2026-04-24

### Added

- Initial release
- Captures every email sent via Craft's mailer by listening to `Mailer::EVENT_AFTER_SEND`
- CP views: log list, single log detail with HTML iframe preview
- Settings: body logging toggle, failure logging toggle, retention days, CP nav label
- Permissions: `accessPlugin-mail-log` (auto) and `mailLog:delete`
- Console commands: `mail-log/logs/prune` and `mail-log/logs/flush`
