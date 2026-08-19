<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Proxy;

use JsonSerializable;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * A proxy the caller wants to exist, before the server has created it.
 *
 * Used by ToxiproxyClient::populate(), which is the idempotent way to declare a
 * whole set of proxies in one request: existing proxies with matching settings
 * are left running, mismatched ones are recreated.
 */
final readonly class ProxyDefinition implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $listen,
        public string $upstream,
        public bool $enabled = true,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('A proxy needs a name.');
        }

        if (trim($upstream) === '') {
            throw new InvalidArgumentException(sprintf('Proxy "%s" needs an upstream address.', $name));
        }

        if (trim($listen) === '') {
            throw new InvalidArgumentException(sprintf(
                'Proxy "%s" needs a listen address. Use "%s:0" to let Toxiproxy pick a free port.',
                $name,
                Address::DEFAULT_HOST,
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        foreach (['name', 'listen', 'upstream'] as $field) {
            if (! isset($payload[$field]) || ! is_string($payload[$field])) {
                throw new InvalidArgumentException(sprintf('Proxy definition is missing a string "%s".', $field));
            }
        }

        /** @var string $name */
        $name = $payload['name'];
        /** @var string $listen */
        $listen = $payload['listen'];
        /** @var string $upstream */
        $upstream = $payload['upstream'];

        return new self($name, $listen, $upstream, (bool) ($payload['enabled'] ?? true));
    }

    /**
     * @return array{name: string, listen: string, upstream: string, enabled: bool}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'listen' => $this->listen,
            'upstream' => $this->upstream,
            'enabled' => $this->enabled,
        ];
    }

    /**
     * @return array{name: string, listen: string, upstream: string, enabled: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toPayload();
    }
}
