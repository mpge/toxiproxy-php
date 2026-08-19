<?php

declare(strict_types=1);

namespace Mpge\Toxiproxy\Support;

/**
 * Reading environment variables, with an injectable map so configuration is
 * testable without mutating the real environment.
 */
final readonly class Environment
{
    /**
     * @param  array<string, string>|null  $values  null means read the real environment
     */
    public function __construct(private ?array $values = null)
    {
    }

    /**
     * @param  array<string, string>  $values
     */
    public static function fake(array $values): self
    {
        return new self($values);
    }

    public static function real(): self
    {
        return new self();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if ($this->values !== null) {
            $value = $this->values[$key] ?? null;

            return $value === null || $value === '' ? $default : $value;
        }

        // getenv() misses variables set only in $_ENV/$_SERVER by a .env
        // loader, which is how most PHP projects configure themselves.
        /** @var mixed $raw */
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($raw === false || $raw === null || $raw === '') {
            return $default;
        }

        return is_scalar($raw) ? (string) $raw : $default;
    }

    public function integer(string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return $value !== null && ctype_digit(ltrim($value, '-')) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }

    public function float(string $key, float $default): float
    {
        $value = $this->get($key);

        return $value !== null && is_numeric($value) ? (float) $value : $default;
    }
}
