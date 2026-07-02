<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Headers;

/**
 * One entity in a MIME tree: its headers, its (not yet transfer-decoded) body
 * bytes, and any child parts. Pure data plus convenience accessors derived
 * from the headers.
 */
final class MimePart
{
    private HeaderValue $contentType;

    private HeaderValue $disposition;

    /** @var list<MimePart> */
    public array $children = [];

    public function __construct(
        public readonly Headers $headers,
        public readonly string $body,
    ) {
        $this->contentType = HeaderValue::parse($headers->get('content-type'));
        $this->disposition = HeaderValue::parse($headers->get('content-disposition'));
    }

    /** Full type, e.g. "text/plain". Defaults per RFC to text/plain. */
    public function contentType(): string
    {
        return $this->contentType->value !== '' ? $this->contentType->value : 'text/plain';
    }

    public function isMultipart(): bool
    {
        return str_starts_with($this->contentType(), 'multipart/');
    }

    public function isRfc822(): bool
    {
        return $this->contentType() === 'message/rfc822';
    }

    public function isText(): bool
    {
        return str_starts_with($this->contentType(), 'text/');
    }

    public function boundary(): ?string
    {
        return $this->contentType->param('boundary');
    }

    /** An arbitrary Content-Type parameter (e.g. "protocol", "smime-type", "method"). */
    public function contentTypeParam(string $name): ?string
    {
        return $this->contentType->param($name);
    }

    public function charset(): ?string
    {
        return $this->contentType->param('charset');
    }

    public function transferEncoding(): string
    {
        return strtolower(trim((string) $this->headers->get('content-transfer-encoding', '7bit')));
    }

    /** "attachment", "inline" or empty string. */
    public function disposition(): string
    {
        return $this->disposition->value;
    }

    public function filename(): ?string
    {
        return $this->disposition->param('filename') ?? $this->contentType->param('name');
    }

    public function contentId(): ?string
    {
        $id = $this->headers->get('content-id');

        return $id !== null ? trim($id, " \t<>") : null;
    }

    public function contentLocation(): ?string
    {
        return $this->headers->get('content-location');
    }

    private ?string $decodedContent = null;

    /**
     * The body with its Content-Transfer-Encoding reversed (base64 / quoted-printable / etc.).
     * Memoised so lazy consumers do not decode the same part twice.
     */
    public function decodedContent(): string
    {
        return $this->decodedContent ??= ContentDecoder::decode($this->body, $this->transferEncoding());
    }

    /**
     * Decoded textual content converted to UTF-8 using the declared charset.
     */
    public function decodedText(): string
    {
        return Charset::toUtf8($this->decodedContent(), $this->charset());
    }
}
