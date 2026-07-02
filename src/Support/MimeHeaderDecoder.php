<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

/**
 * Decodes RFC 2047 "encoded-words" (e.g. =?UTF-8?B?...?= and =?ISO-8859-1?Q?...?=)
 * that appear in headers such as Subject, From and attachment file names.
 */
final class MimeHeaderDecoder
{
    public static function decode(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        // RFC 2047: whitespace separating two adjacent encoded-words is removed.
        $value = preg_replace(
            '/(\?=)\s+(?==\?)/',
            '$1',
            $value
        ) ?? $value;

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
            static function (array $m): string {
                [$full, $charset, $encoding, $text] = $m;

                // Strip any RFC 2231 language tag, e.g. "UTF-8*en".
                $charset = explode('*', $charset)[0];

                if (strtoupper($encoding) === 'B') {
                    $bytes = base64_decode($text, false);
                } else {
                    $bytes = quoted_printable_decode(str_replace('_', ' ', $text));
                }

                if ($bytes === false) {
                    return $full;
                }

                return Charset::toUtf8($bytes, $charset);
            },
            $value
        );

        return $decoded ?? $value;
    }
}
