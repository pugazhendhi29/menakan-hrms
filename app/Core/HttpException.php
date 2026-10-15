<?php

declare(strict_types=1);

namespace App\Core;

final class HttpException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message = '')
    {
        parent::__construct($message ?: match ($status) {
            403 => 'You do not have permission to view this page.',
            404 => 'The page or file you asked for could not be found.',
            405 => 'That action is not allowed here.',
            419 => 'Your form expired. Please go back, refresh the page and try again.',
            default => 'Something went wrong.',
        }, $status);
    }
}
