<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

/**
 * Parses a structured header value such as
 *   `multipart/mixed; boundary="abc"; charset=utf-8`
 * or
 *   `attachment; filename*=UTF-8''%E2%82%AC.txt`
 *
 * into its leading value plus a decoded parameter map. Handles RFC 2231
 * parameter continuations / extended (percent-encoded) values as well as the
 * common-but-illegal habit of stuffing RFC 2047 encoded-words into a quoted
 * parameter.
 */
final class HeaderValue
{
    /**
     * @param  array<string, string>  $params
     */
    private function __construct(
        public readonly string $value,
        public readonly array $params,
    ) {}

    public static function parse(?string $raw): self
    {
        if ($raw === null) {
            return new self('', []);
        }

        $segments = self::splitOnSemicolons($raw);
        $value = strtolower(trim(array_shift($segments) ?? ''));

        /** @var array<string, array{sections: array<int, array{value: string, encoded: bool}>, charset: ?string}> $collected */
        $collected = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment === '' || ! str_contains($segment, '=')) {
                continue;
            }

            [$rawName, $rawVal] = explode('=', $segment, 2);
            $rawName = strtolower(trim($rawName));
            $rawVal = trim($rawVal);

            $encoded = false;
            $section = 0;
            $charset = null;

            // name*  -> extended (charset'lang'value)
            // name*0, name*1 -> continuation sections
            // name*0* -> continuation section that is itself percent-encoded
            if (str_contains($rawName, '*')) {
                $parts = explode('*', $rawName);
                $base = $parts[0];

                if (isset($parts[1]) && is_numeric($parts[1])) {
                    $section = (int) $parts[1];
                    $encoded = isset($parts[2]); // trailing * after the section number
                } else {
                    $encoded = true; // name* form
                }
            } else {
                $base = $rawName;
            }

            $val = self::stripQuotes($rawVal);

            if ($encoded && $section === 0) {
                // charset'lang'value
                if (preg_match("/^([^']*)'([^']*)'(.*)$/s", $val, $m) === 1) {
                    $charset = $m[1] !== '' ? $m[1] : null;
                    $val = $m[3];
                }
            }

            $collected[$base]['sections'][$section] = ['value' => $val, 'encoded' => $encoded];
            $collected[$base]['charset'] = $charset ?? ($collected[$base]['charset'] ?? null);
        }

        $params = [];

        foreach ($collected as $name => $data) {
            $sections = $data['sections'];
            ksort($sections);

            $assembled = '';
            foreach ($sections as $part) {
                $piece = $part['value'];
                if ($part['encoded']) {
                    $piece = rawurldecode($piece);
                }
                $assembled .= $piece;
            }

            if ($data['charset'] !== null) {
                $assembled = Charset::toUtf8($assembled, $data['charset']);
            }

            // Tolerate encoded-words smuggled into a quoted parameter value.
            $assembled = MimeHeaderDecoder::decode($assembled);

            $params[$name] = $assembled;
        }

        return new self($value, $params);
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[strtolower($name)] ?? $default;
    }

    /**
     * @return list<string>
     */
    private static function splitOnSemicolons(string $value): array
    {
        $segments = [];
        $buffer = '';
        $inQuote = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '"') {
                $inQuote = ! $inQuote;
                $buffer .= $char;

                continue;
            }

            if ($char === ';' && ! $inQuote) {
                $segments[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $segments[] = $buffer;

        return $segments;
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
            $value = str_replace('\\"', '"', $value);
        }

        return $value;
    }
}
