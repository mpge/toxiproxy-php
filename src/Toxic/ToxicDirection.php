<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Toxic;

use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * The direction of traffic a toxic applies to.
 *
 * Toxiproxy names these from the client's point of view:
 *
 *   downstream: data flowing from the upstream service back to your client
 *   upstream:   data flowing from your client towards the upstream service
 *
 * Toxiproxy defaults a toxic to downstream when the field is omitted, and this
 * package keeps that default.
 */
enum ToxicDirection: string
{
    case Downstream = 'downstream';
    case Upstream = 'upstream';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException(sprintf(
                'Unknown toxic stream "%s". Toxiproxy accepts only "upstream" or "downstream".',
                $value,
            ));
    }

    public function opposite(): self
    {
        return $this === self::Downstream ? self::Upstream : self::Downstream;
    }

    /**
     * Human-readable explanation, used by the CLI when listing toxics.
     */
    public function describe(): string
    {
        return match ($this) {
            self::Downstream => 'service -> client',
            self::Upstream => 'client -> service',
        };
    }
}
