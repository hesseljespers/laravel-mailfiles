<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Illuminate\Http\UploadedFile;
use Melloww\MailFiles\Contracts\ParsesEml;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Exceptions\MailFileException;
use Melloww\MailFiles\Ole\OleReader;
use Melloww\MailFiles\Parsers\EmlParser;
use Melloww\MailFiles\Parsers\MailparseEmlParser;
use Melloww\MailFiles\Parsers\MsgParser;

/**
 * The single entry point for reading mail files.
 *
 * Auto-detects whether the input is an EML or MSG message and dispatches to the
 * matching parser, always returning the unified {@see Email} value object. This
 * is the class bound in the container and fronted by the `MailFiles` facade.
 */
class MailFileReader
{
    /**
     * @param  'native'|'mailparse'|'auto'  $emlDriver  which EML parser to use
     */
    public function __construct(
        private readonly string $emlDriver = 'native',
        private readonly ParseOptions $options = new ParseOptions,
    ) {}

    /**
     * Read a mail file from disk, detecting its format from its contents.
     */
    public function read(string $path): Email
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MailFileException::fileNotReadable($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw MailFileException::fileNotReadable($path);
        }

        return $this->readString($contents);
    }

    /**
     * Read a message from an in-memory string, detecting its format.
     */
    public function readString(string $contents): Email
    {
        if ($contents === '') {
            throw MailFileException::emptyInput();
        }

        return $this->detectFormat($contents) === MailFormat::Msg
            ? $this->msgParser()->parse($contents)
            : $this->emlParser()->parse($contents);
    }

    /**
     * Read a freshly uploaded mail file (Laravel HTTP upload).
     */
    public function readUploadedFile(UploadedFile $file): Email
    {
        $path = $file->getRealPath();

        if ($path === false || $path === '') {
            throw MailFileException::fileNotReadable($file->getClientOriginalName());
        }

        return $this->read($path);
    }

    /**
     * Read only the envelope + metadata of a mail file, skipping the body,
     * attachments, calendar and nested messages. Ideal for inbox indexing.
     *
     * For EML this reads only the header block from disk (not the whole file).
     */
    public function readHeaders(string $path): Email
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MailFileException::fileNotReadable($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw MailFileException::fileNotReadable($path);
        }

        try {
            $head = fread($handle, 512);

            if ($head === false || $head === '') {
                throw MailFileException::emptyInput();
            }

            if (OleReader::isOle($head)) {
                // MSG needs random access; read the whole file but only touch the
                // small envelope/metadata property streams.
                return $this->msgParser()->parseHeaders($head.(stream_get_contents($handle) ?: ''));
            }

            // Header parsing is format-trivial and gains nothing from the
            // mailparse driver, so always use the native parser here.
            return (new EmlParser($this->options))->parseHeaders($this->readEmlHeaderBlock($handle, $head));
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read only the envelope + metadata from an in-memory string.
     */
    public function readHeadersString(string $contents): Email
    {
        if ($contents === '') {
            throw MailFileException::emptyInput();
        }

        return $this->detectFormat($contents) === MailFormat::Msg
            ? $this->msgParser()->parseHeaders($contents)
            : (new EmlParser($this->options))->parseHeaders($contents);
    }

    /**
     * Force EML parsing of a file regardless of detection.
     */
    public function readEml(string $path): Email
    {
        return $this->emlParser()->parse($this->contents($path));
    }

    /**
     * Force MSG parsing of a file regardless of detection.
     */
    public function readMsg(string $path): Email
    {
        return $this->msgParser()->parse($this->contents($path));
    }

    /**
     * Determine which format a chunk of bytes represents.
     */
    public function detectFormat(string $contents): MailFormat
    {
        return OleReader::isOle($contents) ? MailFormat::Msg : MailFormat::Eml;
    }

    /**
     * Stream every message from a Unix mbox spool, one at a time.
     *
     * @return \Generator<int, Email>
     */
    public function readMbox(string $path): \Generator
    {
        $reader = new MboxReader(fn (string $raw): Email => $this->emlParser()->parse($raw));

        yield from $reader->read($path);
    }

    /**
     * Read every .eml/.msg file in a directory, keyed by path.
     *
     * @return \Generator<string, Email>
     */
    public function readDirectory(string $directory, bool $recursive = false): \Generator
    {
        if (! is_dir($directory)) {
            throw MailFileException::fileNotReadable($directory);
        }

        $iterator = $recursive
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS))
            : new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), ['eml', 'msg'], true)) {
                continue;
            }

            yield $file->getPathname() => $this->read($file->getPathname());
        }
    }

    /**
     * Read from an open EML file only up to (and excluding) the header/body
     * separator, so a headers-only read never loads the full message.
     *
     * @param  resource  $handle
     */
    private function readEmlHeaderBlock($handle, string $initial): string
    {
        $buffer = $initial;

        while (! feof($handle) && ! $this->hasHeaderSeparator($buffer)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
        }

        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $separator) {
            $position = strpos($buffer, $separator);
            if ($position !== false) {
                return substr($buffer, 0, $position);
            }
        }

        return $buffer;
    }

    private function hasHeaderSeparator(string $value): bool
    {
        return str_contains($value, "\r\n\r\n") || str_contains($value, "\n\n") || str_contains($value, "\r\r");
    }

    private function contents(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MailFileException::fileNotReadable($path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw MailFileException::fileNotReadable($path);
        }

        return $contents;
    }

    private function emlParser(): ParsesEml
    {
        return match ($this->emlDriver) {
            'mailparse' => new MailparseEmlParser($this->options),
            'auto' => MailparseEmlParser::isSupported() ? new MailparseEmlParser($this->options) : new EmlParser($this->options),
            default => new EmlParser($this->options),
        };
    }

    private function msgParser(): MsgParser
    {
        return new MsgParser($this->options);
    }
}
