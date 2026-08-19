<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use Mpge\Toxiproxy\Toxic\ToxicCollection;

/**
 * Everything about a proxy that the scoped chaos helpers have to put back.
 *
 * Deliberately excludes listen and upstream: those describe where the proxy
 * lives, not what is currently wrong with it, and silently rebinding a proxy at
 * the end of a test block would be a nasty surprise.
 */
final readonly class ProxySnapshot
{
    public function __construct(
        public bool $enabled,
        public ToxicCollection $toxics,
    ) {
    }
}
