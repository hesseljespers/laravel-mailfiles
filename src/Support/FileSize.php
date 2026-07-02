<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

/**
 * Formats a byte count as a human-readable string (binary units, base 1024).
 */
final class FileSize
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function human(int $bytes, int $precision = 1): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $value = (float) $bytes;
        $unit = 0;
        $last = count(self::UNITS) - 1;

        while ($value >= 1024 && $unit < $last) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.'.$precision.'f %s', $value, self::UNITS[$unit]);
    }
}
