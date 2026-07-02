<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use IteratorAggregate;
use Traversable;

/**
 * A case-insensitive collection of message headers.
 *
 * A header name may legitimately appear more than once (e.g. `Received`), so
 * every value is stored as a list while {@see get()} returns the first match
 * for convenience.
 *
 * @implements IteratorAggregate<string, list<string>>
 */
final class Headers implements IteratorAggregate
{
    /** @var array<string, list<string>> map of lower-cased name => values */
    private array $values = [];

    /** @var array<string, string> map of lower-cased name => original casing */
    private array $originalCase = [];

    /**
     * @param  iterable<array{0: string, 1: string}>  $pairs  ordered [name, value] pairs
     */
    public function __construct(iterable $pairs = [])
    {
        foreach ($pairs as [$name, $value]) {
            $this->add($name, $value);
        }
    }

    public function add(string $name, string $value): void
    {
        $key = strtolower(trim($name));

        $this->values[$key][] = $value;
        $this->originalCase[$key] ??= trim($name);
    }

    /**
     * The first value for a header, or null when absent.
     */
    public function get(string $name, ?string $default = null): ?string
    {
        $key = strtolower(trim($name));

        return $this->values[$key][0] ?? $default;
    }

    /**
     * Every value for a header, in the order they appeared.
     *
     * @return list<string>
     */
    public function all(string $name): array
    {
        return $this->values[strtolower(trim($name))] ?? [];
    }

    public function has(string $name): bool
    {
        return isset($this->values[strtolower(trim($name))]);
    }

    /**
     * All headers as a name => first-value map, preserving the original casing
     * of the first time each header was seen.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $out = [];

        foreach ($this->values as $key => $list) {
            $out[$this->originalCase[$key]] = $list[0];
        }

        return $out;
    }

    /**
     * @return Traversable<string, list<string>>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->values as $key => $list) {
            yield $this->originalCase[$key] => $list;
        }
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
