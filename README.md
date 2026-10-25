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
- Settings for body logging, failure logging, redaction, retention and CP nav label
- Automatic retention cleanup during Craft's garbage collection, plus a
  `php craft mail-log/logs/prune` console command

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
| Log email bodies | On | Persist text and HTML bodies to the database |
| Log failed sends | On | Create an entry when a send fails |
| Redact sensitive emails | On | Don't store or display bodies of system emails that contain single-use links |
| Retention (days) | 0 | How many days to keep entries (0 = forever); applied during garbage collection and by the `prune` console command |
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

## Console

```bash
# Delete entries older than the configured retentionDays
php craft mail-log/logs/prune

# ...or older than a specific number of days
php craft mail-log/logs/prune --days=30

# Delete every entry (interactive confirm)
php craft mail-log/logs/flush
```

When `retentionDays` is set, expired entries are also removed whenever Craft
runs garbage collection, so a cron job is optional. Deletion happens in
batches to avoid long table locks on large logs.

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
- Body storage can grow quickly on high-volume sites. Either disable
  `logBodies` or set a `retentionDays` and schedule `mail-log/logs/prune`.
- Attachments are recorded by filename only, not contents.

## Development

```bash
composer install
composer test     # PHPUnit unit tests
composer phpstan  # static analysis
```

## License

MIT
