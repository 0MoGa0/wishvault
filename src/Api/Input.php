<?php

declare(strict_types=1);

namespace WishVault\Api;

use WishVault\Http\ApiError;

/**
 * Request validation helpers. Errors are collected per field and thrown as
 * one `validation_failed`.
 */
final class Input
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param list<string> $allowed
     */
    public function enum(mixed $value, string $field, array $allowed, string $default): string
    {
        if ($value === null) {
            return $default;
        }
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->errors[$field] = 'invalid';
            return $default;
        }
        return $value;
    }

    public function int(mixed $value, string $field, int $min, int $max, int $default): int
    {
        if ($value === null) {
            return $default;
        }
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && preg_match('/^-?[0-9]{1,9}$/', $value) === 1) {
            $int = (int) $value;
        } else {
            $this->errors[$field] = 'invalid';
            return $default;
        }
        if ($int < $min || $int > $max) {
            $this->errors[$field] = 'invalid';
            return $default;
        }
        return $int;
    }

    public function string(mixed $value, string $field, int $min, int $max, bool $trim = true): string
    {
        if ($value === null) {
            $this->errors[$field] = 'required';
            return '';
        }
        if (!is_string($value)) {
            $this->errors[$field] = 'invalid';
            return '';
        }
        $str = $trim ? trim($value) : $value;
        $len = mb_strlen($str);
        if ($len === 0 && $min > 0) {
            $this->errors[$field] = 'required';
        } elseif ($len < $min) {
            $this->errors[$field] = 'too_short';
        } elseif ($len > $max) {
            $this->errors[$field] = 'too_long';
        }
        return $str;
    }

    public function bool(mixed $value, string $field, bool $default): bool
    {
        return match ($value) {
            null => $default,
            true, 'true', '1' => true,
            false, 'false', '0' => false,
            default => $this->invalid($field, $default),
        };
    }

    public function fail(string $field, string $code): void
    {
        $this->errors[$field] = $code;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw ApiError::validation($this->errors);
        }
    }

    private function invalid(string $field, bool $default): bool
    {
        $this->errors[$field] = 'invalid';
        return $default;
    }
}
