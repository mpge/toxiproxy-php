<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Toxic;

use JsonSerializable;
use Mpge\Toxiproxy\Exception\InvalidArgumentException;
use stdClass;

/**
 * An immutable snapshot of a toxic as Toxiproxy reports it.
 *
 * Mutating helpers return a new instance; nothing here talks to the server.
 * Sending a changed toxic back is the client's job.
 */
final readonly class Toxic implements JsonSerializable
{
    /**
     * @param  array<string, int|float>  $attributes
     */
    public function __construct(
        public string $name,
        public ToxicType $type,
        public ToxicDirection $stream,
        public float $toxicity,
        public array $attributes,
    ) {
        if ($toxicity < 0.0 || $toxicity > 1.0) {
            throw new InvalidArgumentException(sprintf(
                'Toxicity must be between 0.0 and 1.0, got %s.',
                var_export($toxicity, true),
            ));
        }
    }

    /**
     * @param  array<string, int|float>  $attributes
     */
    public static function make(
        ToxicType $type,
        array $attributes = [],
        ToxicDirection $stream = ToxicDirection::Downstream,
        float $toxicity = 1.0,
        ?string $name = null,
    ): self {
        $type->assertAttributesAreKnown($attributes);

        return new self(
            $name ?? $type->defaultName($stream),
            $type,
            $stream,
            $toxicity,
            // array_replace rather than + so the keys stay in the order the
            // upstream Go struct declares them, whatever order they arrived in.
            array_replace($type->defaultAttributes(), $attributes),
        );
    }

    /**
     * Build from a decoded Toxiproxy API payload.
     *
     * Accepts any decoded JSON array rather than insisting on string keys,
     * because json_decode() cannot promise those; every field read below is
     * validated regardless.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $name = $payload['name'] ?? null;
        $type = $payload['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new InvalidArgumentException('Toxic payload is missing a "type" field.');
        }

        $toxicType = ToxicType::fromString($type);
        $stream = isset($payload['stream']) && is_string($payload['stream'])
            ? ToxicDirection::fromString($payload['stream'])
            : ToxicDirection::Downstream;

        $attributes = [];

        if (isset($payload['attributes']) && is_array($payload['attributes'])) {
            /** @var mixed $value */
            foreach ($payload['attributes'] as $key => $value) {
                if (is_string($key) && (is_int($value) || is_float($value))) {
                    $attributes[$key] = $value;
                }
            }
        }

        return new self(
            is_string($name) && $name !== '' ? $name : $toxicType->defaultName($stream),
            $toxicType,
            $stream,
            isset($payload['toxicity']) && is_numeric($payload['toxicity']) ? (float) $payload['toxicity'] : 1.0,
            $attributes,
        );
    }

    public function attribute(string $key): int|float|null
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * @param  array<string, int|float>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        $this->type->assertAttributesAreKnown($attributes);

        return new self(
            $this->name,
            $this->type,
            $this->stream,
            $this->toxicity,
            array_replace($this->attributes, $attributes),
        );
    }

    /**
     * Two toxics are the same when the server would store them identically.
     *
     * Attribute order is not part of that, so it is normalised away rather than
     * being allowed to trigger a pointless update request.
     */
    public function equals(self $other): bool
    {
        if ($this->name !== $other->name || $this->type !== $other->type || $this->stream !== $other->stream) {
            return false;
        }

        if (abs($this->toxicity - $other->toxicity) > PHP_FLOAT_EPSILON) {
            return false;
        }

        $mine = $this->attributes;
        $theirs = $other->attributes;
        ksort($mine);
        ksort($theirs);

        return $mine === $theirs;
    }

    public function withToxicity(float $toxicity): self
    {
        return new self($this->name, $this->type, $this->stream, $toxicity, $this->attributes);
    }

    public function withName(string $name): self
    {
        return new self($name, $this->type, $this->stream, $this->toxicity, $this->attributes);
    }

    public function withStream(ToxicDirection $stream): self
    {
        return new self($this->name, $this->type, $stream, $this->toxicity, $this->attributes);
    }

    /**
     * The exact JSON body Toxiproxy expects on POST /proxies/{proxy}/toxics.
     *
     * Attributes are emitted as an object even when empty, because Go's decoder
     * treats a JSON array as a type error.
     *
     * @return array{name: string, type: string, stream: string, toxicity: float, attributes: object|array<string, int|float>}
     */
    public function toPayload(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'stream' => $this->stream->value,
            'toxicity' => $this->toxicity,
            'attributes' => $this->attributes === [] ? new stdClass() : $this->attributes,
        ];
    }

    /**
     * @return array{name: string, type: string, stream: string, toxicity: float, attributes: array<string, int|float>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'stream' => $this->stream->value,
            'toxicity' => $this->toxicity,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @return array{name: string, type: string, stream: string, toxicity: float, attributes: object|array<string, int|float>}
     */
    public function jsonSerialize(): array
    {
        return $this->toPayload();
    }

    /**
     * A compact one-line description, e.g. `latency_downstream (latency: 1000ms, jitter: 0ms)`.
     */
    public function describe(): string
    {
        $units = $this->type->attributeUnits();
        $parts = [];

        foreach ($this->attributes as $key => $value) {
            $parts[] = sprintf('%s: %s%s', $key, $value, $units[$key] ?? '');
        }

        $suffix = $this->toxicity < 1.0 ? sprintf(' @ %.0f%% of connections', $this->toxicity * 100) : '';

        return sprintf('%s (%s)%s', $this->name, $parts === [] ? 'no attributes' : implode(', ', $parts), $suffix);
    }
}
