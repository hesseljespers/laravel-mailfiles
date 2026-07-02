<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Closure;
use Generator;
use Melloww\MailFiles\Exceptions\MailFileException;
use Melloww\MailFiles\Parsers\EmlParser;

/**
 * Reads a Unix mbox spool (a concatenation of RFC 822 messages separated by
 * "From " postmark lines) one message at a time.
 *
 * Iteration is streaming: the file is read line by line and only a single
 * message is held in memory at once, so very large spools stay light.
 */
final class MboxReader
{
    /** @var Closure(string): Email */
    private Closure $parser;

    /**
     * @param  (callable(string): Email)|null  $parser  how to parse each message (defaults to the native EML parser)
     */
    public function __construct(?callable $parser = null)
    {
        $this->parser = $parser !== null
            ? Closure::fromCallable($parser)
            : static fn (string $raw): Email => (new EmlParser)->parse($raw);
    }

    /**
     * Yield each message in the mbox file.
     *
     * @return Generator<int, Email>
     */
    public function read(string $path): Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MailFileException::fileNotReadable($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw MailFileException::fileNotReadable($path);
        }

        try {
            $buffer = '';
            $started = false;
            $previousBlank = true; // start-of-file counts as a boundary

            while (($line = fgets($handle)) !== false) {
                $isBlank = trim($line, "\r\n") === '';

                // A postmark is a "From " line at the start of the file or right
                // after a blank line — this avoids splitting on body text.
                if ($previousBlank && preg_match('/^From \S/', $line) === 1) {
                    if ($started && trim($buffer) !== '') {
                        yield ($this->parser)($buffer);
                    }
                    $buffer = '';
                    $started = true;
                    $previousBlank = false;

                    continue;
                }

                if ($started) {
                    $buffer .= $this->unescape($line);
                }

                $previousBlank = $isBlank;
            }

            if ($started && trim($buffer) !== '') {
                yield ($this->parser)($buffer);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Yield each message from an in-memory mbox string.
     *
     * @return Generator<int, Email>
     */
    public function readString(string $contents): Generator
    {
        $messages = preg_split('/(?:^|\r\n|\n)From \S[^\r\n]*(?:\r\n|\n)/', $contents) ?: [];

        foreach ($messages as $message) {
            if (trim($message) === '') {
                continue;
            }

            yield ($this->parser)($this->unescapeAll($message));
        }
    }

    /**
     * Reverse mboxrd quoting on a single line (">From " => "From ", ">>From " => ">From ").
     */
    private function unescape(string $line): string
    {
        return preg_replace('/^>(>*From )/', '$1', $line) ?? $line;
    }

    private function unescapeAll(string $message): string
    {
        return preg_replace('/^>(>*From )/m', '$1', $message) ?? $message;
    }
}
