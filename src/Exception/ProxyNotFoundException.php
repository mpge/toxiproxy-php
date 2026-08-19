<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;

class ProxyNotFoundException extends RuntimeException implements ToxiproxyException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Proxy "%s" does not exist on this Toxiproxy server.', $name));
    }
}
