<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Closure;
use Melloww\MailFiles\Enums\AttachmentDisposition;
use Melloww\MailFiles\Support\FileSize;
use Melloww\MailFiles\Support\MimeTypeGuesser;

/**
 * A file carried by a message.
 *
 * Covers both regular attachments and inline/embedded resources (e.g. images
 * referenced from the HTML body through a `cid:` URL). Use {@see disposition()}
 * / {@see isInline()} to tell them apart.
 *
 * The payload and any nested message are resolved lazily: constructing and
 * listing attachments (reading names, types, disposition) never materialises
 * the bytes, so iterating a message with large attachments stays cheap and only
 * the payloads you actually read are decoded/held.
 */
final class Attachment
{
    private ?string $resolvedContent = null;

    private bool $embeddedResolved = false;

    private ?Email $resolvedEmbedded = null;

    /**
     * @param  Closure(): string|string  $content  the decoded bytes, or a resolver producing them
     * @param  Closure(): (Email|null)|Email|null  $embeddedMessage  a nested message (or resolver), when this attachment is itself an email
     */
    public function __construct(
        private readonly ?string $filename,
        private readonly ?string $contentType,
        private readonly Closure|string $content,
        private readonly AttachmentDisposition $disposition = AttachmentDisposition::Attachment,
        private readonly ?string $contentId = null,
        private readonly ?string $contentLocation = null,
        private readonly Closure|Email|null $embeddedMessage = null,
    ) {}

    /**
     * The (long) file name, e.g. `invoice.pdf`. May be null for nameless inline parts.
     */
    public function filename(): ?string
    {
        return $this->filename;
    }

    /**
     * The MIME type exactly as declared by the message. Fast and lazy, but may
     * be null when the source omitted it. See {@see mimeType()} for a value that
     * falls back to content detection.
     */
    public function contentType(): ?string
    {
        return $this->contentType;
    }

    /**
     * The best-known MIME type: the declared {@see contentType()} when present,
     * otherwise one sniffed from the payload's magic bytes (never from the
     * file-name extension, which is spoofable).
     *
     * Note: when the type has to be detected, this resolves the content.
     */
    public function mimeType(): ?string
    {
        return $this->contentType ?? MimeTypeGuesser::fromContents($this->content());
    }

    /**
     * The raw, fully decoded bytes of the attachment (resolved and memoised on
     * first access).
     */
    public function content(): string
    {
        if ($this->resolvedContent === null) {
            $this->resolvedContent = $this->content instanceof Closure
                ? ($this->content)()
                : $this->content;
        }

        return $this->resolvedContent;
    }

    /**
     * Size of the decoded content in bytes. Note: this resolves the content.
     */
    public function size(): int
    {
        return strlen($this->content());
    }

    /**
     * The size as a human-readable string, e.g. "47.1 KB". Resolves the content.
     */
    public function humanSize(int $precision = 1): string
    {
        return FileSize::human($this->size(), $precision);
    }

    /**
     * How this part is meant to be used: a genuine {@see AttachmentDisposition::Attachment}
     * or an {@see AttachmentDisposition::Inline} body resource.
     */
    public function disposition(): AttachmentDisposition
    {
        return $this->disposition;
    }

    /**
     * Whether this part is displayed inline (e.g. an embedded image) rather than
     * offered as a download.
     */
    public function isInline(): bool
    {
        return $this->disposition === AttachmentDisposition::Inline;
    }

    /**
     * A convenience for the common case: an inline image such as a signature
     * logo, social-media icon or tracking pixel.
     */
    public function isEmbeddedImage(): bool
    {
        return $this->isInline()
            && $this->contentType !== null
            && str_starts_with(strtolower($this->contentType), 'image/');
    }

    /**
     * The Content-ID used to reference an inline part from the HTML body,
     * without the surrounding angle brackets.
     */
    public function contentId(): ?string
    {
        return $this->contentId;
    }

    public function contentLocation(): ?string
    {
        return $this->contentLocation;
    }

    /**
     * Whether this attachment is itself a mail message (a `.eml` or `.msg`) that
     * can be parsed recursively.
     */
    public function isEmbeddedMessage(): bool
    {
        return $this->embeddedMessage() !== null;
    }

    /**
     * The parsed nested message, when this attachment is itself an email
     * (resolved and memoised on first access).
     */
    public function embeddedMessage(): ?Email
    {
        if (! $this->embeddedResolved) {
            $this->resolvedEmbedded = $this->embeddedMessage instanceof Closure
                ? ($this->embeddedMessage)()
                : $this->embeddedMessage;
            $this->embeddedResolved = true;
        }

        return $this->resolvedEmbedded;
    }

    /**
     * The lower-cased file extension (without the dot), derived from the file name.
     */
    public function extension(): ?string
    {
        if ($this->filename === null) {
            return null;
        }

        $ext = pathinfo($this->filename, PATHINFO_EXTENSION);

        return $ext === '' ? null : strtolower($ext);
    }

    /**
     * Persist the attachment to disk. Returns the number of bytes written.
     */
    public function saveTo(string $path): int
    {
        $bytes = file_put_contents($path, $this->content());

        if ($bytes === false) {
            throw new \RuntimeException("Unable to write attachment to [{$path}].");
        }

        return $bytes;
    }

    /**
     * @return array{filename: string|null, content_type: string|null, size: int, disposition: string, inline: bool, content_id: string|null, is_message: bool}
     */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'content_type' => $this->contentType,
            'size' => $this->size(),
            'disposition' => $this->disposition->value,
            'inline' => $this->isInline(),
            'content_id' => $this->contentId,
            'is_message' => $this->isEmbeddedMessage(),
        ];
    }
}
