<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Client;

/**
 * The minimal slice of an HTTP response this package needs.
 */
final readonly class Response
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isEmpty(): bool
    {
        return trim($this->body) === '';
    }
}
