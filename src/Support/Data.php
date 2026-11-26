<?php

declare(strict_types=1);

namespace WishVault\Support;

/**
 * Typed access to decoded JSON (upstream responses, request bodies).
 * Every getter returns null when the key is missing or has the wrong type.
 */
final class Data
{
    /**
     * @return array<array-key, mixed>|null
     */
    public static function arr(mixed $value, string|int|null $key = null): ?array
    {
        $value = self::pick($value, $key);
        return is_array($value) ? $value : null;
    }

    /**
     * List of arrays; non-array entries are dropped.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value, string|int|null $key = null): array
    {
        $out = [];
        foreach (self::arr($value, $key) ?? [] as $item) {
            if (is_array($item)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    public static function str(mixed $value, string|int|null $key = null): ?string
    {
        $value = self::pick($value, $key);
        return is_string($value) ? $value : null;
    }

    /** Integers and integral numeric strings. */
    public static function int(mixed $value, string|int|null $key = null): ?int
    {
        $value = self::pick($value, $key);
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]{1,18}$/', $value) === 1) {
            return (int) $value;
        }
        return null;
    }

    public static function bool(mixed $value, string|int|null $key = null): ?bool
    {
        $value = self::pick($value, $key);
        return is_bool($value) ? $value : null;
    }

    private static function pick(mixed $value, string|int|null $key): mixed
    {
        if ($key === null) {
            return $value;
        }
        return is_array($value) ? ($value[$key] ?? null) : null;
    }
}
