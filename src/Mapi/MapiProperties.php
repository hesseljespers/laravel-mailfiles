<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Mapi;

use DateTimeImmutable;
use DateTimeZone;
use Melloww\MailFiles\Ole\DirectoryEntry;
use Melloww\MailFiles\Ole\OleReader;
use Melloww\MailFiles\Support\Charset;

/**
 * Reads the MAPI property set of one storage in a `.msg` file.
 *
 * Variable-length properties (strings, binaries) are stored as individual
 * `__substg1.0_<id><type>` streams; fixed-length ones (ints, dates, booleans)
 * live packed inside the `__properties_version1.0` stream. This class merges
 * both into a single typed lookup.
 */
final class MapiProperties
{
    /** Difference in seconds between the FILETIME epoch (1601) and the Unix epoch (1970). */
    private const FILETIME_UNIX_DELTA = 11644473600;

    /** @var array<int, array{type: int, value: mixed, entry: ?DirectoryEntry, read: bool}> keyed by property id */
    private array $properties = [];

    public function __construct(
        private readonly OleReader $ole,
        DirectoryEntry $storage,
        int $fixedHeaderSize,
    ) {
        foreach ($ole->children($storage) as $child) {
            if (! $child->isStream()) {
                continue;
            }

            // Variable-length streams are only *located* here; their bytes are
            // read on demand so large attachment payloads are never held unless
            // actually accessed.
            if (preg_match('/^__substg1\.0_([0-9A-Fa-f]{4})([0-9A-Fa-f]{4})$/', $child->name, $m) === 1) {
                $this->properties[hexdec($m[1])] = [
                    'type' => hexdec($m[2]),
                    'value' => null,
                    'entry' => $child,
                    'read' => false,
                ];

                continue;
            }

            if (strcasecmp($child->name, '__properties_version1.0') === 0) {
                $this->parseFixed($ole->readStream($child), $fixedHeaderSize);
            }
        }
    }

    /**
     * Resolve (and memoise) a property's raw value, reading its stream lazily.
     */
    private function rawValue(int $propertyId): mixed
    {
        if (! isset($this->properties[$propertyId])) {
            return null;
        }

        if (! $this->properties[$propertyId]['read']) {
            $entry = $this->properties[$propertyId]['entry'];
            $this->properties[$propertyId]['value'] = $entry !== null ? $this->ole->readStream($entry) : null;
            $this->properties[$propertyId]['read'] = true;
        }

        return $this->properties[$propertyId]['value'];
    }

    public function has(int $propertyId): bool
    {
        return isset($this->properties[$propertyId]);
    }

    /**
     * A textual property decoded to UTF-8 (handles PT_UNICODE and PT_STRING8).
     */
    public function text(int $propertyId, string $ansiCharset = 'Windows-1252'): ?string
    {
        if (! isset($this->properties[$propertyId])) {
            return null;
        }

        $type = $this->properties[$propertyId]['type'];
        $value = $this->rawValue($propertyId);

        if (! is_string($value)) {
            return null;
        }

        $decoded = match ($type) {
            PropertyTag::TYPE_UNICODE => (string) mb_convert_encoding($value, 'UTF-8', 'UTF-16LE'),
            PropertyTag::TYPE_STRING8 => Charset::toUtf8($value, $ansiCharset),
            default => $value,
        };

        $decoded = rtrim($decoded, "\0");

        return $decoded === '' ? null : $decoded;
    }

    /**
     * Raw bytes of a binary property.
     */
    public function binary(int $propertyId): ?string
    {
        $value = $this->rawValue($propertyId);

        return is_string($value) ? $value : null;
    }

    public function integer(int $propertyId): ?int
    {
        $value = $this->rawValue($propertyId);

        return is_int($value) ? $value : null;
    }

    public function dateTime(int $propertyId): ?DateTimeImmutable
    {
        $value = $this->rawValue($propertyId);

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    /**
     * Parse the fixed-length property table that follows a header of the given size.
     */
    private function parseFixed(string $data, int $headerSize): void
    {
        $length = strlen($data);

        for ($offset = $headerSize; $offset + 16 <= $length; $offset += 16) {
            $tag = $this->u32($data, $offset);
            $type = $tag & 0xFFFF;
            $propertyId = ($tag >> 16) & 0xFFFF;

            // Variable-length values are stored as streams; only decode fixed ones here.
            $value = $this->decodeFixed($type, substr($data, $offset + 8, 8));

            if ($value !== null && ! isset($this->properties[$propertyId])) {
                $this->properties[$propertyId] = ['type' => $type, 'value' => $value, 'entry' => null, 'read' => true];
            }
        }
    }

    private function decodeFixed(int $type, string $bytes): int|DateTimeImmutable|null
    {
        return match ($type) {
            PropertyTag::TYPE_SHORT => $this->u16($bytes, 0),
            PropertyTag::TYPE_LONG => $this->u32($bytes, 0),
            PropertyTag::TYPE_BOOLEAN => $this->u16($bytes, 0),
            PropertyTag::TYPE_I8 => $this->u64($bytes, 0),
            PropertyTag::TYPE_SYSTIME => $this->fileTime($bytes),
            default => null,
        };
    }

    private function fileTime(string $bytes): ?DateTimeImmutable
    {
        if (strlen($bytes) < 8) {
            return null;
        }

        $filetime = $this->u64($bytes, 0);

        if ($filetime <= 0) {
            return null;
        }

        $unix = intdiv($filetime, 10000000) - self::FILETIME_UNIX_DELTA;

        try {
            return (new DateTimeImmutable('@'.$unix))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function u16(string $data, int $offset): int
    {
        $slice = substr($data, $offset, 2);

        return strlen($slice) === 2 ? (unpack('v', $slice)[1] ?? 0) : 0;
    }

    private function u32(string $data, int $offset): int
    {
        $slice = substr($data, $offset, 4);

        return strlen($slice) === 4 ? (unpack('V', $slice)[1] ?? 0) : 0;
    }

    private function u64(string $data, int $offset): int
    {
        $low = $this->u32($data, $offset);
        $high = $this->u32($data, $offset + 4);

        return $low + ($high * 4294967296);
    }
}
