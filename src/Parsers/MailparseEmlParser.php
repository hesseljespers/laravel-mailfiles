<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Parsers;

use Melloww\MailFiles\Contracts\ParsesEml;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Exceptions\MailFileException;
use Melloww\MailFiles\Headers;
use Melloww\MailFiles\ParseOptions;
use Melloww\MailFiles\Support\MimePart;
use Melloww\MailFiles\Support\MimeTreeReader;

/**
 * Optional EML driver backed by the PHP `mailparse` extension.
 *
 * It only delegates the *structural* parsing (the part tree, header lines and
 * body byte offsets) to mailparse; transfer decoding, charset conversion and
 * the body/attachment classification all flow through the same tested
 * {@see MimeTreeReader} the native driver uses, so both produce identical
 * {@see Email} objects.
 */
final class MailparseEmlParser implements ParsesEml
{
    private MimeTreeReader $reader;

    public function __construct(
        private readonly ParseOptions $options = new ParseOptions,
    ) {
        $this->reader = new MimeTreeReader;
    }

    /**
     * Whether the underlying extension is available in the current runtime.
     */
    public static function isSupported(): bool
    {
        return extension_loaded('mailparse');
    }

    public function parse(string $raw): Email
    {
        return $this->parseAtDepth($raw, 0);
    }

    public function parseAtDepth(string $raw, int $depth): Email
    {
        if (! self::isSupported()) {
            throw new MailFileException(
                "The 'mailparse' EML driver requires the PHP mailparse extension, which is not loaded. ".
                "Install it (pecl install mailparse) or use the default 'native' driver."
            );
        }

        $resource = mailparse_msg_create();

        try {
            mailparse_msg_parse($resource, $raw);

            $byId = [];

            foreach (mailparse_msg_get_structure($resource) as $id) {
                $section = mailparse_msg_get_part($resource, $id);
                $data = mailparse_msg_get_part_data($section);

                $byId[$id] = new MimePart(
                    $this->buildHeaders($data['headers'] ?? []),
                    $this->sliceBody($raw, $data),
                );
            }

            $root = $this->assembleTree($byId);
        } finally {
            mailparse_msg_free($resource);
        }

        if ($root === null) {
            throw MailFileException::emptyInput();
        }

        return $this->reader->toEmail($root, $this->options, $depth);
    }

    /**
     * @param  array<string, string|list<string>>  $rawHeaders
     */
    private function buildHeaders(array $rawHeaders): Headers
    {
        $headers = new Headers;

        foreach ($rawHeaders as $name => $value) {
            foreach ((array) $value as $single) {
                $headers->add((string) $name, (string) $single);
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sliceBody(string $raw, array $data): string
    {
        $start = $data['starting-pos-body'] ?? null;
        $end = $data['ending-pos-body'] ?? null;

        if (is_int($start) && is_int($end) && $end >= $start) {
            return substr($raw, $start, $end - $start);
        }

        return '';
    }

    /**
     * Rebuild the parent/child tree from mailparse's dotted section ids
     * ("1", "1.1", "1.2", ...).
     *
     * @param  array<string, MimePart>  $byId
     */
    private function assembleTree(array $byId): ?MimePart
    {
        if ($byId === []) {
            return null;
        }

        $ids = array_keys($byId);
        usort($ids, 'strnatcmp');

        $root = null;

        foreach ($ids as $id) {
            $lastDot = strrpos($id, '.');

            if ($lastDot === false) {
                $root ??= $byId[$id];

                continue;
            }

            $parentId = substr($id, 0, $lastDot);

            if (isset($byId[$parentId])) {
                $byId[$parentId]->children[] = $byId[$id];
            }
        }

        return $root ?? $byId[$ids[0]];
    }
}
