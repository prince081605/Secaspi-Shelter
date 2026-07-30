<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A checkout step that cannot proceed — the session is spent, expired, or the
 * caller asked for a transition the state machine does not allow. Carries the
 * HTTP status the controller should answer with so the mapping lives in one
 * place instead of being re-derived at every call site.
 */
class PaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409)
    {
        parent::__construct($message);
    }

    public static function expired(): self
    {
        return new self('This payment link has expired. Start the payment again from your donation history.', 410);
    }

    public static function notPayable(): self
    {
        return new self('This payment has already been completed or cancelled.', 409);
    }

    public static function wrongStep(): self
    {
        return new self('That step is out of order for this payment.', 409);
    }
}
