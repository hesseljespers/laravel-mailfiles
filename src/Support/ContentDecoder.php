<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

/**
 * Reverses a MIME Content-Transfer-Encoding.
 */
final class ContentDecoder
{
    public static function decode(string $content, string $encoding): string
    {
        return match (strtolower(trim($encoding))) {
            'base64' => base64_decode(self::stripWhitespace($content), false) ?: '',
            'quoted-printable' => quoted_printable_decode($content),
            'x-uuencode', 'uuencode', 'uue' => self::uudecode($content),
            default => $content, // 7bit, 8bit, binary, identity
        };
    }

    private static function stripWhitespace(string $content): string
    {
        return preg_replace('/\s+/', '', $content) ?? $content;
    }

    private static function uudecode(string $content): string
    {
        $decoded = @convert_uudecode($content);

        return $decoded === false ? $content : $decoded;
    }
}
