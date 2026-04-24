# Mail Log Changelog

## 1.0.0 - 2026-04-24

### Added

- Initial release
- Captures every email sent via Craft's mailer by listening to `Mailer::EVENT_AFTER_SEND`
- CP views: log list, single log detail with HTML iframe preview
- Settings: body logging toggle, failure logging toggle, retention days, CP nav label
- Permissions: `accessPlugin-mail-log` (auto) and `mailLog:delete`
- Console commands: `mail-log/logs/prune` and `mail-log/logs/flush`
