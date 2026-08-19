<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Exception;

use RuntimeException;

class ToxicNotFoundException extends RuntimeException implements ToxiproxyException
{
    public static function named(string $proxy, string $toxic): self
    {
        return new self(sprintf('Toxic "%s" does not exist on proxy "%s".', $toxic, $proxy));
    }
}
