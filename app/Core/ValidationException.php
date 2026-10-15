<?php

declare(strict_types=1);

namespace App\Core;

/** Thrown by services when business rules reject an action; the message is safe to show users. */
final class ValidationException extends \RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
