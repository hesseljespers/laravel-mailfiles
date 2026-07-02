<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Throwable;

/**
 * Charset normalisation helpers. Converts arbitrary labelled text to UTF-8,
 * preferring the mbstring extension and transparently falling back to iconv
 * for legacy encodings mbstring does not know about.
 */
final class Charset
{
    /**
     * Charset labels that, for our purposes, are already (or close enough to) UTF-8.
     */
    private const UTF8_ALIASES = ['utf-8', 'utf8', 'us-ascii', 'ascii', 'unknown-8bit', ''];

    public static function toUtf8(string $value, ?string $charset): string
    {
        if ($value === '') {
            return $value;
        }

        $charset = strtolower(trim((string) $charset));

        if (in_array($charset, self::UTF8_ALIASES, true)) {
            // Already UTF-8 (or unlabelled ASCII-ish). Scrub any invalid bytes so
            // downstream consumers never choke on malformed sequences.
            return self::scrub($value);
        }

        $label = self::canonicalLabel($charset);

        // Prefer mbstring, but it raises a ValueError for encodings it does not
        // support, so guard it and fall back to iconv.
        try {
            if (in_array(strtolower($label), array_map('strtolower', mb_list_encodings()), true)) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $label);

                if (is_string($converted) && $converted !== '') {
                    return $converted;
                }
            }
        } catch (Throwable) {
            // fall through to iconv
        }

        if (function_exists('iconv')) {
            $converted = @iconv($label, 'UTF-8//TRANSLIT//IGNORE', $value);

            if ($converted !== false) {
                return $converted;
            }
        }

        // Last resort: assume the bytes were Windows-1252 / Latin-1ish.
        $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');

        return is_string($converted) && $converted !== '' ? $converted : self::scrub($value);
    }

    /**
     * Map a few common but non-canonical charset labels to ones the converters know.
     */
    private static function canonicalLabel(string $charset): string
    {
        return match ($charset) {
            'latin1', 'latin-1', 'cp819', 'iso8859-1' => 'ISO-8859-1',
            'cp1252', 'windows1252', 'win-1252' => 'Windows-1252',
            'utf16', 'utf-16' => 'UTF-16',
            'ks_c_5601-1987', 'ksc5601' => 'EUC-KR',
            'gb2312' => 'GB18030',
            default => $charset,
        };
    }

    /**
     * Map a Windows code-page identifier (as found in MAPI PR_INTERNET_CPID /
     * PR_MESSAGE_CODEPAGE properties) to a charset label.
     */
    public static function fromCodepage(int $codepage): string
    {
        return match ($codepage) {
            0, 65001 => 'UTF-8',
            1200 => 'UTF-16LE',
            1201 => 'UTF-16BE',
            1250 => 'Windows-1250',
            1251 => 'Windows-1251',
            1252 => 'Windows-1252',
            1253 => 'Windows-1253',
            1254 => 'Windows-1254',
            1255 => 'Windows-1255',
            1256 => 'Windows-1256',
            1257 => 'Windows-1257',
            1258 => 'Windows-1258',
            28591 => 'ISO-8859-1',
            28592 => 'ISO-8859-2',
            28595 => 'ISO-8859-5',
            28599 => 'ISO-8859-9',
            28605 => 'ISO-8859-15',
            932 => 'Shift_JIS',
            936 => 'GB18030',
            949 => 'EUC-KR',
            950 => 'Big5',
            20127 => 'US-ASCII',
            default => 'Windows-1252',
        };
    }

    private static function scrub(string $value): string
    {
        $clean = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return is_string($clean) ? $clean : $value;
    }
}
