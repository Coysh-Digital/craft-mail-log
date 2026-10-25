<?php

namespace CoyshDigital\MailLog\mail;

use Closure;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Transparent decorator around a Symfony mailer transport that reports
 * send failures before re-throwing them.
 *
 * yii\mail\BaseMailer only fires EVENT_AFTER_SEND when the transport
 * returns normally. When it throws (connection refused, authentication
 * failure, API error, ...) the exception escapes before the event fires
 * and craft\mail\Mailer::send() swallows it and returns false, so there is
 * no event for listeners to observe. Wrapping the transport is the only
 * transport-agnostic way to see those failures without replacing the
 * mailer itself.
 *
 * Behaviour is otherwise identical to the wrapped transport: the original
 * exception is always re-thrown unchanged.
 */
class FailureReportingTransport implements TransportInterface
{
    /**
     * @param TransportInterface $transport The real transport
     * @param Closure(Throwable): void $onFailure Called with the exception when a send fails
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly Closure $onFailure,
    ) {
    }

    /**
     * Returns the transport being decorated.
     */
    public function getInnerTransport(): TransportInterface
    {
        return $this->transport;
    }

    /**
     * @inheritdoc
     */
    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->transport->send($message, $envelope);
        } catch (Throwable $e) {
            try {
                ($this->onFailure)($e);
            } catch (Throwable) {
                // Reporting must never mask the original failure.
            }
            throw $e;
        }
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }
}
