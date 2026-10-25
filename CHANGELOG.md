# Mail Log Changelog

## Unreleased

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

### Changed

- The log list no longer loads email bodies, which makes it much faster on large logs.
- Pruning deletes in batches of 1,000 to avoid long table locks.
- Empty CC/BCC/Reply-to lists and attachment lists are stored as `NULL` rather than `[]`.
- Accessibility: labelled search controls, descriptive delete buttons and a title on the preview iframe.

## 1.0.0 - 2026-04-24

### Added

- Initial release
- Captures every email sent via Craft's mailer by listening to `Mailer::EVENT_AFTER_SEND`
- CP views: log list, single log detail with HTML iframe preview
- Settings: body logging toggle, failure logging toggle, retention days, CP nav label
- Permissions: `accessPlugin-mail-log` (auto) and `mailLog:delete`
- Console commands: `mail-log/logs/prune` and `mail-log/logs/flush`
