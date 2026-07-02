<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Ole;

/**
 * A single directory entry inside an OLE2 / Compound File (MS-CFB).
 *
 * Entries form a red-black tree per storage; siblings are linked through the
 * left/right ids and a storage points at its children through {@see $childId}.
 */
final class DirectoryEntry
{
    public const TYPE_UNKNOWN = 0;

    public const TYPE_STORAGE = 1;

    public const TYPE_STREAM = 2;

    public const TYPE_ROOT = 5;

    public const NOSTREAM = 0xFFFFFFFF;

    public function __construct(
        public readonly string $name,
        public readonly int $type,
        public readonly int $leftSibling,
        public readonly int $rightSibling,
        public readonly int $childId,
        public readonly int $startSector,
        public readonly int $size,
    ) {}

    public static function parse(string $raw): self
    {
        $nameLength = self::u16($raw, 64);

        // nameLength counts bytes including the terminating UTF-16 null.
        $nameBytes = $nameLength > 2 ? substr($raw, 0, $nameLength - 2) : '';
        $name = $nameBytes === ''
            ? ''
            : rtrim((string) mb_convert_encoding($nameBytes, 'UTF-8', 'UTF-16LE'), "\0");

        $low = self::u32($raw, 120);
        $high = self::u32($raw, 124);

        return new self(
            name: $name,
            type: strlen($raw) > 66 ? ord($raw[66]) : self::TYPE_UNKNOWN,
            leftSibling: self::u32($raw, 68),
            rightSibling: self::u32($raw, 72),
            childId: self::u32($raw, 76),
            startSector: self::u32($raw, 116),
            size: $low + ($high * 4294967296),
        );
    }

    public function isStream(): bool
    {
        return $this->type === self::TYPE_STREAM;
    }

    public function isStorage(): bool
    {
        return $this->type === self::TYPE_STORAGE;
    }

    public function isRoot(): bool
    {
        return $this->type === self::TYPE_ROOT;
    }

    private static function u16(string $raw, int $offset): int
    {
        $slice = substr($raw, $offset, 2);

        return strlen($slice) === 2 ? (unpack('v', $slice)[1] ?? 0) : 0;
    }

    private static function u32(string $raw, int $offset): int
    {
        $slice = substr($raw, $offset, 4);

        return strlen($slice) === 4 ? (unpack('V', $slice)[1] ?? 0) : 0;
    }
}
