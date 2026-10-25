<?php

namespace CoyshDigital\MailLog\tests\unit;

use CoyshDigital\MailLog\mail\FailureReportingTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Mailer\Envelope;
use Throwable;

class FailureReportingTransportTest extends TestCase
{
    public function testSuccessfulSendIsPassedThroughWithoutReporting(): void
    {
        $reported = [];
        $transport = new FailureReportingTransport(new NullTransport(), function(Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        $sent = $transport->send($this->email());

        self::assertInstanceOf(SentMessage::class, $sent);
        self::assertSame([], $reported);
    }

    public function testFailureIsReportedAndRethrownUnchanged(): void
    {
        $error = new TransportException('Connection refused');
        $reported = [];
        $transport = new FailureReportingTransport($this->failingTransport($error), function(Throwable $e) use (&$reported) {
            $reported[] = $e;
        });

        try {
            $transport->send($this->email());
            self::fail('Expected the transport exception to propagate.');
        } catch (TransportException $e) {
            self::assertSame($error, $e);
        }

        self::assertSame([$error], $reported);
    }

    public function testReporterErrorsDoNotMaskTheOriginalFailure(): void
    {
        $error = new TransportException('Connection refused');
        $transport = new FailureReportingTransport($this->failingTransport($error), function() {
            throw new RuntimeException('Database is down');
        });

        $this->expectExceptionObject($error);
        $transport->send($this->email());
    }

    public function testDescribesItselfAsTheInnerTransport(): void
    {
        $inner = new NullTransport();
        $transport = new FailureReportingTransport($inner, function() {
        });

        self::assertSame((string) $inner, (string) $transport);
        self::assertSame($inner, $transport->getInnerTransport());
    }

    private function email(): Email
    {
        return (new Email())
            ->from('from@example.com')
            ->to('to@example.com')
            ->subject('Test')
            ->text('Body');
    }

    private function failingTransport(Throwable $error): TransportInterface
    {
        return new class($error) implements TransportInterface {
            public function __construct(private readonly Throwable $error)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw $this->error;
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        };
    }
}
