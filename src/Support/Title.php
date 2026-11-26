<?php

declare(strict_types=1);

namespace WishVault\Support;

/**
 * Title normalization for library matching. Deliberately conservative:
 * no special rules for roman numerals or editions.
 */
final class Title
{
    private static ?\Transliterator $transliterator = null;

    public static function normalize(string $title): string
    {
        $t = mb_strtolower($title, 'UTF-8');
        $t = str_replace('&', ' and ', $t);
        $t = str_replace(['™', '®', '©'], '', $t);
        self::$transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');
        if (self::$transliterator !== null) {
            $converted = self::$transliterator->transliterate($t);
            if (is_string($converted)) {
                $t = strtolower($converted);
            }
        }
        return (string) preg_replace('/[^a-z0-9]+/', '', $t);
    }
}
