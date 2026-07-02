<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Ole;

use Melloww\MailFiles\Exceptions\CorruptMailFile;

/**
 * Minimal, read-only reader for the OLE2 / Compound File Binary Format
 * (Microsoft MS-CFB) that Outlook `.msg` files are stored in.
 *
 * Parses the FAT, mini-FAT and directory tree so individual streams can be
 * pulled out by name. Only the pieces needed to read a `.msg` are implemented.
 */
final class OleReader
{
    public const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** Sector values above this are special markers (ENDOFCHAIN, FREESECT, ...). */
    private const MAX_REGULAR_SECTOR = 0xFFFFFFFA;

    private int $sectorSize;

    private int $miniSectorSize;

    private int $miniStreamCutoff;

    /** @var list<int> main FAT: sector -> next sector */
    private array $fat = [];

    /** @var list<int> mini FAT: mini-sector -> next mini-sector */
    private array $miniFat = [];

    /** @var array<int, DirectoryEntry> */
    private array $entries = [];

    private DirectoryEntry $root;

    private string $miniStream = '';

    private function __construct(private readonly string $data)
    {
        $this->parseHeader();
        $this->buildFat();
        $this->readDirectory();
        $this->buildMiniStream();
        $this->buildMiniFat();
    }

    public static function fromString(string $data): self
    {
        if (! self::isOle($data)) {
            throw CorruptMailFile::make('missing OLE2 compound-file signature');
        }

        return new self($data);
    }

    public static function isOle(string $data): bool
    {
        return strlen($data) >= 512 && str_starts_with($data, self::SIGNATURE);
    }

    public function root(): DirectoryEntry
    {
        return $this->root;
    }

    /**
     * Direct children of a storage entry, in tree order.
     *
     * @return list<DirectoryEntry>
     */
    public function children(DirectoryEntry $storage): array
    {
        $result = [];
        $visited = [];
        $this->collectSiblings($storage->childId, $result, $visited);

        return $result;
    }

    /**
     * Read the fully assembled bytes of a stream entry.
     */
    public function readStream(DirectoryEntry $entry): string
    {
        if ($entry->size === 0) {
            return '';
        }

        if ($entry->isRoot() || $entry->size >= $this->miniStreamCutoff) {
            return substr($this->readMainChain($entry->startSector, $entry->size), 0, $entry->size);
        }

        return substr($this->readMiniChain($entry->startSector, $entry->size), 0, $entry->size);
    }

    /**
     * @param  list<DirectoryEntry>  $result  collected by reference
     * @param  array<int, bool>  $visited  cycle guard
     */
    private function collectSiblings(int $id, array &$result, array &$visited): void
    {
        if ($id === DirectoryEntry::NOSTREAM || ! isset($this->entries[$id]) || isset($visited[$id])) {
            return;
        }

        $visited[$id] = true;
        $node = $this->entries[$id];

        $this->collectSiblings($node->leftSibling, $result, $visited);
        $result[] = $node;
        $this->collectSiblings($node->rightSibling, $result, $visited);
    }

    private function parseHeader(): void
    {
        $sectorShift = $this->u16(30);
        $this->sectorSize = 1 << ($sectorShift > 0 ? $sectorShift : 9);

        $miniSectorShift = $this->u16(32);
        $this->miniSectorSize = 1 << ($miniSectorShift > 0 ? $miniSectorShift : 6);

        $cutoff = $this->u32(56);
        $this->miniStreamCutoff = $cutoff > 0 ? $cutoff : 4096;
    }

    private function buildFat(): void
    {
        $difat = [];

        // First 109 DIFAT entries live in the header (offset 76).
        for ($i = 0; $i < 109; $i++) {
            $difat[] = $this->u32(76 + ($i * 4));
        }

        // Follow any additional DIFAT sectors.
        $sector = $this->u32(68); // first DIFAT sector
        $numDifat = $this->u32(72);
        $entriesPerSector = intdiv($this->sectorSize, 4);
        $guard = 0;

        while ($sector <= self::MAX_REGULAR_SECTOR && $guard++ < $numDifat + 16) {
            $raw = $this->readSector($sector);
            $values = $this->unpackU32($raw);
            $next = array_pop($values) ?? DirectoryEntry::NOSTREAM;

            foreach ($values as $value) {
                $difat[] = $value;
            }

            $sector = $next;
        }

        foreach ($difat as $fatSector) {
            if ($fatSector > self::MAX_REGULAR_SECTOR) {
                continue;
            }

            foreach ($this->unpackU32($this->readSector($fatSector)) as $value) {
                $this->fat[] = $value;
            }
        }

        unset($entriesPerSector);
    }

    private function readDirectory(): void
    {
        $firstDirSector = $this->u32(48);
        $directory = $this->readMainChain($firstDirSector);

        $count = intdiv(strlen($directory), 128);

        for ($i = 0; $i < $count; $i++) {
            $this->entries[$i] = DirectoryEntry::parse(substr($directory, $i * 128, 128));
        }

        foreach ($this->entries as $entry) {
            if ($entry->isRoot()) {
                $this->root = $entry;

                return;
            }
        }

        throw CorruptMailFile::make('no root storage entry found');
    }

    private function buildMiniStream(): void
    {
        $this->miniStream = $this->root->size > 0
            ? substr($this->readMainChain($this->root->startSector, $this->root->size), 0, $this->root->size)
            : '';
    }

    private function buildMiniFat(): void
    {
        $sector = $this->u32(60); // first mini-FAT sector
        $numMiniFat = $this->u32(64);
        $guard = 0;

        while ($sector <= self::MAX_REGULAR_SECTOR && $guard++ < $numMiniFat + 16) {
            foreach ($this->unpackU32($this->readSector($sector)) as $value) {
                $this->miniFat[] = $value;
            }

            $sector = $this->fat[$sector] ?? DirectoryEntry::NOSTREAM;
        }
    }

    private function readMainChain(int $start, int $limit = PHP_INT_MAX): string
    {
        $out = '';
        $sector = $start;
        $guard = 0;
        $maxIterations = count($this->fat) + 16;

        while ($sector <= self::MAX_REGULAR_SECTOR && $guard++ < $maxIterations) {
            $out .= $this->readSector($sector);

            if (strlen($out) >= $limit) {
                break;
            }

            $sector = $this->fat[$sector] ?? DirectoryEntry::NOSTREAM;
        }

        return $out;
    }

    private function readMiniChain(int $start, int $limit): string
    {
        $out = '';
        $sector = $start;
        $guard = 0;
        $maxIterations = count($this->miniFat) + 16;

        while ($sector <= self::MAX_REGULAR_SECTOR && $guard++ < $maxIterations) {
            $offset = $sector * $this->miniSectorSize;
            $out .= substr($this->miniStream, $offset, $this->miniSectorSize);

            if (strlen($out) >= $limit) {
                break;
            }

            $sector = $this->miniFat[$sector] ?? DirectoryEntry::NOSTREAM;
        }

        return $out;
    }

    private function readSector(int $sector): string
    {
        // Sector n begins at file offset (n + 1) * sectorSize (the header occupies the first sector).
        return substr($this->data, ($sector + 1) * $this->sectorSize, $this->sectorSize);
    }

    /**
     * @return list<int>
     */
    private function unpackU32(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $usable = intdiv(strlen($raw), 4) * 4;

        if ($usable === 0) {
            return [];
        }

        /** @var array<int, int> $values */
        $values = unpack('V*', substr($raw, 0, $usable));

        return array_values($values);
    }

    private function u16(int $offset): int
    {
        $slice = substr($this->data, $offset, 2);

        return strlen($slice) === 2 ? (unpack('v', $slice)[1] ?? 0) : 0;
    }

    private function u32(int $offset): int
    {
        $slice = substr($this->data, $offset, 4);

        return strlen($slice) === 4 ? (unpack('V', $slice)[1] ?? 0) : 0;
    }
}
