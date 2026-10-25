<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\helpers\EmailPreview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmailPreviewTest extends TestCase
{
    private const META = '<meta http-equiv="Content-Security-Policy"';

    /**
     * @return array<string, array{string, string}>
     */
    public static function documentProvider(): array
    {
        return [
            'full document' => [
                '<!DOCTYPE html><html lang="en"><head><title>x</title></head><body><img src="https://t.example/p.gif"></body></html>',
                '<!DOCTYPE html><html lang="en"><head>' . self::META,
            ],
            'head with attributes' => [
                '<html><HEAD data-x="1"><style>p{}</style></HEAD></html>',
                '<html><HEAD data-x="1">' . self::META,
            ],
            'no head' => [
                '<!doctype html><html><body>hi</body></html>',
                '<!doctype html><html>' . self::META,
            ],
            'doctype only' => [
                '<!DOCTYPE html><p>hi</p>',
                '<!DOCTYPE html>' . self::META,
            ],
            'fragment' => [
                '<p>hi</p>',
                self::META,
            ],
            '<header> is not <head>' => [
                '<header>hi</header>',
                self::META,
            ],
        ];
    }

    #[DataProvider('documentProvider')]
    public function testInsertsCspMetaInTheRightPlace(string $html, string $expectedPrefix): void
    {
        $result = EmailPreview::blockRemoteContent($html);

        self::assertStringStartsWith($expectedPrefix, $result);
        self::assertSame(1, substr_count($result, self::META));
        // The original markup is preserved in full.
        self::assertSame($html, str_replace($this->metaTag(), '', $result));
    }

    public function testPolicyBlocksRemoteLoads(): void
    {
        $policy = EmailPreview::BLOCK_REMOTE_CSP;

        self::assertStringContainsString("default-src 'none'", $policy);
        self::assertStringNotContainsString('http', $policy);
        self::assertStringNotContainsString('*', $policy);
    }

    private function metaTag(): string
    {
        $result = EmailPreview::blockRemoteContent('');
        self::assertStringStartsWith(self::META, $result);
        return $result;
    }
}
