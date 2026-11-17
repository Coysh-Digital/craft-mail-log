<?php

namespace CoyshDigital\MailLog\helpers;

use Craft;

/**
 * Optional compression of stored email bodies.
 *
 * Bodies are the bulk of the log's storage: HTML emails typically weigh
 * tens of kilobytes but deflate to a quarter of that or less. Compressed
 * values are written into the existing text columns as a marker prefix
 * followed by base64-encoded deflate data, so no schema change is needed and
 * entries stored without compression stay readable. Search never looks at
 * bodies, so it is unaffected.
 */
class BodyCodec
{
    /**
     * Prefix identifying a compressed value. A plain body is vanishingly
     * unlikely to start with it.
     */
    public const PREFIX = '{mail-log:deflate}';

    /**
     * Bodies shorter than this (in bytes) are stored as-is; the saving
     * wouldn't justify the encoding overhead.
     */
    public const MIN_LENGTH = 1024;

    /**
     * Shown in place of a body whose compressed data can't be decoded.
     */
    public const UNREADABLE = '[Mail Log could not decode this stored body.]';

    /**
     * Compress a body for storage. Returns the body unchanged if it is
     * empty or small, if zlib is unavailable, or if compression wouldn't
     * make it smaller.
     */
    public static function encode(?string $body): ?string
    {
        if ($body === null || strlen($body) < self::MIN_LENGTH || !function_exists('gzdeflate')) {
            return $body;
        }
        $deflated = gzdeflate($body, 6);
        if ($deflated === false) {
            return $body;
        }
        $encoded = self::PREFIX . base64_encode($deflated);
        return strlen($encoded) < strlen($body) ? $encoded : $body;
    }

    /**
     * Restore a stored body. Values without the marker (bodies stored
     * uncompressed, including every entry from before compression existed)
     * are returned unchanged.
     */
    public static function decode(?string $stored): ?string
    {
        if ($stored === null || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $body = $raw !== false && function_exists('gzinflate') ? @gzinflate($raw) : false;
        if ($body === false) {
            Craft::warning('Mail Log could not decompress a stored email body.', 'mail-log');
            return self::UNREADABLE;
        }
        return $body;
    }
}
