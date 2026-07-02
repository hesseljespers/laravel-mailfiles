<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use DateTimeImmutable;
use Throwable;

/**
 * Best-effort parsing of the many date formats that turn up in mail headers.
 */
final class DateParser
{
    public static function parse(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Trailing parenthetical timezone names break the parser, e.g. "... +0200 (CEST)".
        $cleaned = preg_replace('/\s*\([^)]*\)\s*$/', '', $value) ?? $value;

        foreach (array_unique([$cleaned, $value]) as $candidate) {
            try {
                return new DateTimeImmutable($candidate);
            } catch (Throwable) {
                // try the next candidate
            }
        }

        $timestamp = strtotime($value);

        return $timestamp !== false
            ? (new DateTimeImmutable)->setTimestamp($timestamp)
            : null;
    }
}
