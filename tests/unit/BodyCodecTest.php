<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\helpers\BodyCodec;
use PHPUnit\Framework\TestCase;

class BodyCodecTest extends TestCase
{
    private function html(): string
    {
        return '<html><body>' . str_repeat('<p style="color:#333;font-family:Arial">Hello, this is a typical marketing email.</p>', 400) . '</body></html>';
    }

    public function testLargeBodyIsCompressedAndRoundTrips(): void
    {
        $html = $this->html();
        $encoded = BodyCodec::encode($html);

        self::assertNotSame($html, $encoded);
        self::assertStringStartsWith(BodyCodec::PREFIX, $encoded);
        self::assertLessThan(strlen($html) / 4, strlen($encoded));
        self::assertSame($html, BodyCodec::decode($encoded));
    }

    public function testMultibyteBodyRoundTrips(): void
    {
        $body = str_repeat("Zażółć gęślą jaźń — 日本語のメール ✉️\n", 100);
        self::assertSame($body, BodyCodec::decode(BodyCodec::encode($body)));
    }

    public function testSmallBodiesAreStoredAsIs(): void
    {
        self::assertSame('Short', BodyCodec::encode('Short'));
    }

    public function testNullAndEmptyPassThrough(): void
    {
        self::assertNull(BodyCodec::encode(null));
        self::assertNull(BodyCodec::decode(null));
        self::assertSame('', BodyCodec::decode(''));
    }

    public function testIncompressibleBodyIsNotInflated(): void
    {
        // Random bytes don't deflate, and base64 would only make them bigger.
        $body = base64_encode(random_bytes(2000));
        $encoded = BodyCodec::encode($body);

        self::assertSame($body, BodyCodec::decode($encoded));
        self::assertLessThanOrEqual(strlen($body), strlen($encoded));
    }

    public function testLegacyUncompressedBodiesAreReturnedUnchanged(): void
    {
        $legacy = '<p>Stored before compression existed</p>';
        self::assertSame($legacy, BodyCodec::decode($legacy));
    }

    public function testCorruptPayloadDoesNotThrow(): void
    {
        self::assertSame(BodyCodec::UNREADABLE, BodyCodec::decode(BodyCodec::PREFIX . '!!!not-base64!!!'));
        self::assertSame(BodyCodec::UNREADABLE, BodyCodec::decode(BodyCodec::PREFIX . base64_encode('not deflate data')));
    }
}
