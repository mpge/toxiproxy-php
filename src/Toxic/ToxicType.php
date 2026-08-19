<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Toxic;

use Mpge\Toxiproxy\Exception\InvalidArgumentException;

/**
 * The toxic types registered by the upstream Toxiproxy server.
 *
 * This list mirrors the Register() calls in Shopify/toxiproxy's toxics package
 * exactly. Toxiproxy rejects any other type with HTTP 400, so there is nothing
 * to gain from being permissive here.
 *
 * Note that "bring a service down" is deliberately absent: upstream models it
 * as the proxy's `enabled` flag rather than as a toxic.
 */
enum ToxicType: string
{
    /** Delay each packet. Attributes: latency (ms), jitter (ms). */
    case Latency = 'latency';

    /** Cap throughput. Attribute: rate (KB/s). 0 means no data flows at all. */
    case Bandwidth = 'bandwidth';

    /** Delay the TCP close. Attribute: delay (ms). */
    case SlowClose = 'slow_close';

    /** Stop all data, then close the connection. Attribute: timeout (ms), 0 means never close. */
    case Timeout = 'timeout';

    /** Close the connection with a TCP RST. Attribute: timeout (ms), 0 means reset immediately. */
    case ResetPeer = 'reset_peer';

    /** Chop packets into smaller pieces. Attributes: average_size, size_variation (bytes), delay (microseconds). */
    case Slicer = 'slicer';

    /** Close the connection after N bytes. Attribute: bytes. */
    case LimitData = 'limit_data';

    /** Pass everything through untouched. No attributes. Useful as a placeholder. */
    case Noop = 'noop';

    public static function fromString(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException(sprintf(
                'Unknown toxic type "%s". Toxiproxy supports: %s.',
                $value,
                implode(', ', self::names()),
            ));
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The attribute keys Toxiproxy understands for this type, in the order the
     * upstream Go struct declares them.
     *
     * @return list<string>
     */
    public function attributeNames(): array
    {
        return match ($this) {
            self::Latency => ['latency', 'jitter'],
            self::Bandwidth => ['rate'],
            self::SlowClose => ['delay'],
            self::Timeout, self::ResetPeer => ['timeout'],
            self::Slicer => ['average_size', 'size_variation', 'delay'],
            self::LimitData => ['bytes'],
            self::Noop => [],
        };
    }

    /**
     * The value Toxiproxy assumes when an attribute is omitted. Every upstream
     * attribute is a Go int/int64 zero value.
     *
     * @return array<string, int>
     */
    public function defaultAttributes(): array
    {
        return array_fill_keys($this->attributeNames(), 0);
    }

    /**
     * The unit each attribute is expressed in, for CLI output and error messages.
     *
     * @return array<string, string>
     */
    public function attributeUnits(): array
    {
        return match ($this) {
            self::Latency => ['latency' => 'ms', 'jitter' => 'ms'],
            self::Bandwidth => ['rate' => 'KB/s'],
            self::SlowClose => ['delay' => 'ms'],
            self::Timeout, self::ResetPeer => ['timeout' => 'ms'],
            self::Slicer => ['average_size' => 'bytes', 'size_variation' => 'bytes', 'delay' => 'us'],
            self::LimitData => ['bytes' => 'bytes'],
            self::Noop => [],
        };
    }

    /**
     * Reject attribute keys Toxiproxy would silently ignore.
     *
     * Upstream decodes attributes into a typed struct, so a misspelled key is
     * dropped without complaint and the toxic quietly does nothing. Catching it
     * here turns a confusing no-op into an immediate, obvious failure.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function assertAttributesAreKnown(array $attributes): void
    {
        $known = $this->attributeNames();
        $unknown = array_diff(array_keys($attributes), $known);

        if ($unknown === []) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'Toxic "%s" has no attribute %s. Toxiproxy would silently ignore it. Valid attributes: %s.',
            $this->value,
            implode(', ', array_map(static fn (string $key): string => '"'.$key.'"', $unknown)),
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }

    /**
     * The default toxic name Toxiproxy generates when none is supplied.
     */
    public function defaultName(ToxicDirection $direction): string
    {
        return $this->value.'_'.$direction->value;
    }
}
