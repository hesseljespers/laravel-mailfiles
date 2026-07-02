<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Address;

/**
 * Parses address header values ("Name <a@b.com>, c@d.com; ...") into Address
 * objects. Handles quoted display names containing commas, RFC 2047 encoded
 * names, and the semicolon-separated lists Outlook tends to produce.
 */
final class AddressParser
{
    /**
     * @return list<Address>
     */
    public static function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $addresses = [];

        foreach (self::splitTopLevel($value) as $part) {
            $address = self::parseOne($part);

            if ($address !== null) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    public static function parseOne(string $part): ?Address
    {
        $part = trim($part);

        if ($part === '') {
            return null;
        }

        $name = null;
        $email = null;

        if (preg_match('/^(.*)<([^>]*)>\s*$/s', $part, $m) === 1) {
            $name = trim($m[1]);
            $email = trim($m[2]);
        } else {
            $email = $part;
        }

        // Legacy "email (Display Name)" form.
        if ($name === null || $name === '') {
            if (preg_match('/^(\S+@\S+?)\s*\((.+)\)\s*$/', $email, $cm) === 1) {
                $email = trim($cm[1]);
                $name = trim($cm[2]);
            }
        }

        $name = self::cleanName($name);
        $email = self::cleanEmail($email);

        if ($email === null && $name === null) {
            return null;
        }

        return new Address($email, $name);
    }

    private static function cleanName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = self::unquote(trim($name));
        $name = MimeHeaderDecoder::decode($name);
        $name = trim($name);

        return $name === '' ? null : $name;
    }

    private static function cleanEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email, " \t\r\n\"'<>");

        // Outlook sometimes stores the SMTP address with a leading addr-type, e.g. "SMTP:a@b.com".
        if (preg_match('/^smtp:(.+)$/i', $email, $m) === 1) {
            $email = trim($m[1]);
        }

        return $email === '' ? null : $email;
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
        }

        return $value;
    }

    /**
     * Split a header value on commas/semicolons that are not inside quotes or angle brackets.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $value): array
    {
        $parts = [];
        $buffer = '';
        $inQuote = false;
        $angleDepth = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"') {
                $inQuote = ! $inQuote;
                $buffer .= $char;

                continue;
            }

            if (! $inQuote) {
                if ($char === '<') {
                    $angleDepth++;
                    $buffer .= $char;

                    continue;
                }

                if ($char === '>') {
                    $angleDepth = max(0, $angleDepth - 1);
                    $buffer .= $char;

                    continue;
                }

                if (($char === ',' || $char === ';') && $angleDepth === 0) {
                    if (trim($buffer) !== '') {
                        $parts[] = $buffer;
                    }
                    $buffer = '';

                    continue;
                }
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }
}
