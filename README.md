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
- CP view to browse, inspect and delete log entries
- Settings for body logging, failure logging, retention and CP nav label
- `php craft mail-log/logs/prune` console command for scheduled cleanup

## How it works

The plugin hooks `craft\mail\Mailer::EVENT_AFTER_SEND`. This event is defined
on Yii's `BaseMailer` and fired inside its `send()` method, so it is
transport-agnostic — it will fire no matter which adapter the site has
configured.

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
| Log email bodies | On | Persist text and HTML bodies to the database |
| Log failed sends | On | Create an entry when `Mailer::send()` returns false |
| Retention (days) | 0 | How many days to keep entries (0 = forever); applied by the `prune` console command |
| CP nav label | empty | Override the sidebar label |

## Permissions

- `accessPlugin-mail-log` (auto-registered) — view the log
- `mailLog:delete` — delete individual entries or flush all

## Console

```bash
# Delete entries older than the configured retentionDays
php craft mail-log/logs/prune

# Delete every entry (interactive confirm)
php craft mail-log/logs/flush
```

Schedule the prune command from cron to keep the log table tidy.

## Data model

A single table `{{%mail_log_entries}}`. Address columns are stored as JSON
and decoded on read.

## Known limitations

- If a mailer's `sendMessage()` throws a fatal, uncaught exception — i.e.
  the failure bubbles up rather than being converted to a `false` return
  value — `EVENT_AFTER_SEND` does not fire and the send is not logged.
  In practice Craft's own mailer catches failures and reports them as
  `isSuccessful = false`, so failures are captured.
- Body storage can grow quickly on high-volume sites. Either disable
  `logBodies` or set a `retentionDays` and schedule `mail-log/logs/prune`.
- Attachments are recorded by filename only, not contents.

## License

MIT
