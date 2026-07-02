<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Parsers;

use DateTimeImmutable;
use Melloww\MailFiles\Address;
use Melloww\MailFiles\Attachment;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\AttachmentDisposition;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Headers;
use Melloww\MailFiles\Mapi\MapiProperties;
use Melloww\MailFiles\Mapi\PropertyTag;
use Melloww\MailFiles\Mapi\RtfCompression;
use Melloww\MailFiles\Mapi\RtfConverter;
use Melloww\MailFiles\Ole\DirectoryEntry;
use Melloww\MailFiles\Ole\OleReader;
use Melloww\MailFiles\ParseOptions;
use Melloww\MailFiles\Support\AddressParser;
use Melloww\MailFiles\Support\AttachmentClassifier;
use Melloww\MailFiles\Support\Charset;
use Melloww\MailFiles\Support\DateParser;
use Melloww\MailFiles\Support\HeaderParser;
use Melloww\MailFiles\Support\MimeHeaderDecoder;
use Melloww\MailFiles\Support\NestedMessageResolver;

/**
 * Dependency-free reader for Outlook `.msg` (MAPI) messages.
 *
 * Walks the OLE2 compound file with {@see OleReader}, decodes the MAPI property
 * set with {@see MapiProperties}, and emits the same {@see Email} value object
 * the EML parser produces.
 */
final class MsgParser
{
    /** Header sizes of the fixed-property table (MS-OXMSG). */
    private const TOP_LEVEL_HEADER = 32;

    private const EMBEDDED_MESSAGE_HEADER = 24;

    private const SUBSTORAGE_HEADER = 8;

    /** Stream/storage name holding an embedded message (PR_ATTACH_DATA_OBJ). */
    private const EMBEDDED_MESSAGE_OBJECT = '__substg1.0_3701000d';

    public function __construct(
        private readonly ParseOptions $options = new ParseOptions,
    ) {}

    public function parse(string $raw): Email
    {
        return $this->parseAtDepth($raw, 0);
    }

    public function parseAtDepth(string $raw, int $depth): Email
    {
        $ole = OleReader::fromString($raw);

        return $this->parseStorage($ole, $ole->root(), self::TOP_LEVEL_HEADER, $depth);
    }

    /**
     * Parse only the envelope/metadata, skipping bodies (including RTF
     * decompression) and attachments.
     */
    public function parseHeaders(string $raw): Email
    {
        $ole = OleReader::fromString($raw);

        return $this->parseStorage($ole, $ole->root(), self::TOP_LEVEL_HEADER, 0, headersOnly: true);
    }

    /**
     * Read a single message storage (the OLE root, or an embedded-message
     * sub-storage) into an Email.
     */
    private function parseStorage(OleReader $ole, DirectoryEntry $storage, int $messageHeaderSize, int $depth, bool $headersOnly = false): Email
    {
        $props = new MapiProperties($ole, $storage, $messageHeaderSize);

        $ansiCharset = $this->ansiCharset($props);

        $headers = $this->transportHeaders($props, $ansiCharset);
        $this->addMapiMetadataHeaders($headers, $props, $ansiCharset);

        [$to, $cc, $bcc] = $this->recipients($ole, $storage, $ansiCharset, $headers);

        $sender = $this->addressFrom(
            $props,
            PropertyTag::SENDER_SMTP_ADDRESS,
            PropertyTag::SENDER_ADDRTYPE,
            PropertyTag::SENDER_EMAIL_ADDRESS,
            PropertyTag::SENDER_NAME,
            $ansiCharset,
        );

        $from = $this->addressFrom(
            $props,
            PropertyTag::SENT_REPRESENTING_SMTP_ADDRESS,
            PropertyTag::SENT_REPRESENTING_ADDRTYPE,
            PropertyTag::SENT_REPRESENTING_EMAIL_ADDRESS,
            PropertyTag::SENT_REPRESENTING_NAME,
            $ansiCharset,
        ) ?? $sender ?? AddressParser::parseList($headers->get('from'))[0] ?? null;

        $subject = $props->text(PropertyTag::SUBJECT, $ansiCharset)
            ?? $this->decodedHeader($headers, 'subject');

        $date = $props->dateTime(PropertyTag::CLIENT_SUBMIT_TIME)
            ?? $props->dateTime(PropertyTag::MESSAGE_DELIVERY_TIME)
            ?? DateParser::parse($headers->get('date'));

        $messageId = $props->text(PropertyTag::INTERNET_MESSAGE_ID, $ansiCharset)
            ?? $this->messageIdHeader($headers);

        $textBody = null;
        $htmlBody = null;
        $rtfBody = null;
        $attachments = [];

        // Bodies and attachments are the expensive part — skip them entirely for
        // a headers-only read.
        if (! $headersOnly) {
            $textBody = $props->text(PropertyTag::BODY, $ansiCharset);
            $htmlBody = $this->htmlBody($props);
            $rtfBody = $this->rtfBody($props);

            // Some Outlook messages only carry a useful RTF body. Recover HTML
            // (when the RTF encapsulates it) or plain text from it as a fallback.
            if ($rtfBody !== null && $rtfBody !== '') {
                if (($htmlBody === null || $htmlBody === '') && RtfConverter::isEncapsulatedHtml($rtfBody)) {
                    $htmlBody = RtfConverter::toHtml($rtfBody, $ansiCharset);
                }

                if (($textBody === null || $textBody === '') && ($htmlBody === null || $htmlBody === '')) {
                    $textBody = RtfConverter::toText($rtfBody, $ansiCharset);
                }
            }

            $attachments = $this->attachments($ole, $storage, $ansiCharset, $depth, $htmlBody);
        }

        return new Email(
            format: MailFormat::Msg,
            subject: $subject,
            from: $from,
            sender: $sender,
            to: $to,
            cc: $cc,
            bcc: $bcc,
            replyTo: AddressParser::parseList($headers->get('reply-to')),
            date: $date,
            messageId: $messageId !== null ? trim($messageId, " <>\t") : null,
            textBody: $textBody,
            htmlBody: $htmlBody,
            attachments: $attachments,
            headers: $this->synthesizeHeaders($headers, $subject, $from, $to, $cc, $date, $messageId),
            rtfBody: $rtfBody,
            headersOnly: $headersOnly,
        );
    }

    private function rtfBody(MapiProperties $props): ?string
    {
        $compressed = $props->binary(PropertyTag::RTF_COMPRESSED);

        return $compressed !== null ? RtfCompression::decompress($compressed) : null;
    }

    /**
     * Expose MAPI priority/sensitivity/threading properties through the headers
     * bag, so the format-agnostic Email helpers work for .msg files too. Real
     * transport headers, when present, take precedence.
     */
    private function addMapiMetadataHeaders(Headers $headers, MapiProperties $props, string $ansiCharset): void
    {
        if (! $headers->has('importance') && ($importance = $props->integer(PropertyTag::IMPORTANCE)) !== null) {
            $headers->add('Importance', match ($importance) {
                0 => 'low',
                2 => 'high',
                default => 'normal',
            });
        }

        if (! $headers->has('sensitivity') && ($sensitivity = $props->integer(PropertyTag::SENSITIVITY)) !== null) {
            $label = match ($sensitivity) {
                1 => 'Personal',
                2 => 'Private',
                3 => 'Company-Confidential',
                default => null,
            };

            if ($label !== null) {
                $headers->add('Sensitivity', $label);
            }
        }

        if (! $headers->has('in-reply-to') && ($inReplyTo = $props->text(PropertyTag::IN_REPLY_TO_ID, $ansiCharset)) !== null) {
            $headers->add('In-Reply-To', $inReplyTo);
        }

        if (! $headers->has('references') && ($references = $props->text(PropertyTag::INTERNET_REFERENCES, $ansiCharset)) !== null) {
            $headers->add('References', $references);
        }
    }

    private function ansiCharset(MapiProperties $props): string
    {
        $codepage = $props->integer(PropertyTag::INTERNET_CPID)
            ?? $props->integer(PropertyTag::MESSAGE_CODEPAGE);

        return $codepage !== null ? Charset::fromCodepage($codepage) : 'Windows-1252';
    }

    private function transportHeaders(MapiProperties $props, string $ansiCharset): Headers
    {
        $raw = $props->text(PropertyTag::TRANSPORT_MESSAGE_HEADERS, $ansiCharset);

        return $raw !== null ? HeaderParser::parse($raw) : new Headers;
    }

    private function htmlBody(MapiProperties $props): ?string
    {
        $bytes = $props->binary(PropertyTag::HTML);

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $codepage = $props->integer(PropertyTag::INTERNET_CPID);
        $charset = $codepage !== null ? Charset::fromCodepage($codepage) : 'UTF-8';

        return Charset::toUtf8($bytes, $charset);
    }

    /**
     * @return array{0: list<Address>, 1: list<Address>, 2: list<Address>}
     */
    private function recipients(OleReader $ole, DirectoryEntry $root, string $ansiCharset, Headers $headers): array
    {
        $to = [];
        $cc = [];
        $bcc = [];

        foreach ($ole->children($root) as $entry) {
            if (! $entry->isStorage() || ! $this->isStorage($entry, '__recip_version1.0')) {
                continue;
            }

            $props = new MapiProperties($ole, $entry, self::SUBSTORAGE_HEADER);

            $address = $this->addressFrom(
                $props,
                PropertyTag::SMTP_ADDRESS,
                PropertyTag::ADDRTYPE,
                PropertyTag::EMAIL_ADDRESS,
                PropertyTag::DISPLAY_NAME,
                $ansiCharset,
            );

            if ($address === null) {
                continue;
            }

            match ($props->integer(PropertyTag::RECIPIENT_TYPE)) {
                PropertyTag::RECIPIENT_CC => $cc[] = $address,
                PropertyTag::RECIPIENT_BCC => $bcc[] = $address,
                default => $to[] = $address,
            };
        }

        // Fall back to the transport headers when a message carries no structured recipients.
        if ($to === [] && $cc === [] && $bcc === []) {
            return [
                AddressParser::parseList($headers->get('to')),
                AddressParser::parseList($headers->get('cc')),
                AddressParser::parseList($headers->get('bcc')),
            ];
        }

        return [$to, $cc, $bcc];
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(OleReader $ole, DirectoryEntry $storage, string $ansiCharset, int $depth, ?string $htmlBody): array
    {
        $attachments = [];

        foreach ($ole->children($storage) as $entry) {
            if (! $entry->isStorage() || ! $this->isStorage($entry, '__attach_version1.0')) {
                continue;
            }

            $props = new MapiProperties($ole, $entry, self::SUBSTORAGE_HEADER);

            $filename = $props->text(PropertyTag::ATTACH_LONG_FILENAME, $ansiCharset)
                ?? $props->text(PropertyTag::ATTACH_FILENAME, $ansiCharset);
            $contentType = $props->text(PropertyTag::ATTACH_MIME_TAG, $ansiCharset);
            $contentId = $props->text(PropertyTag::ATTACH_CONTENT_ID, $ansiCharset);
            $contentLocation = $props->text(PropertyTag::ATTACH_CONTENT_LOCATION, $ansiCharset);
            $flags = $props->integer(PropertyTag::ATTACH_FLAGS) ?? 0;

            // Outlook flags a body-referenced image with ATT_MHTML_REF (0x4); hidden
            // attachments (PR_ATTACHMENT_HIDDEN) are likewise part of the body.
            $explicitInline = ($flags & 0x4) !== 0 || $props->integer(PropertyTag::ATTACHMENT_HIDDEN) === 1;

            $disposition = AttachmentClassifier::classify(
                htmlBody: $htmlBody,
                contentId: $contentId,
                contentLocation: $contentLocation,
                explicitInline: $explicitInline,
                explicitAttachment: false,
                insideRelated: false,
                isImage: str_starts_with(strtolower((string) $contentType), 'image/'),
            );

            if ($props->has(PropertyTag::ATTACH_DATA_BINARY)) {
                // A regular by-value attachment. Its bytes (and any nested-message
                // detection) are resolved lazily so large payloads stay unread
                // until requested.
                $options = $this->options;

                $attachments[] = new Attachment(
                    filename: $filename,
                    contentType: $contentType,
                    content: static fn (): string => (string) $props->binary(PropertyTag::ATTACH_DATA_BINARY),
                    disposition: $disposition,
                    contentId: $contentId,
                    contentLocation: $contentLocation,
                    embeddedMessage: static fn (): ?Email => NestedMessageResolver::resolve(
                        (string) $props->binary(PropertyTag::ATTACH_DATA_BINARY),
                        $contentType,
                        $filename,
                        $options,
                        $depth,
                    ),
                );

                continue;
            }

            // Otherwise this may be an embedded Outlook message, stored as a
            // nested message storage rather than a binary stream.
            $embedded = $this->embeddedMessage($ole, $entry, $depth);

            if ($embedded === null) {
                continue;
            }

            $attachments[] = new Attachment(
                filename: $filename ?? (($embedded->subject() ?? 'message').'.msg'),
                contentType: $contentType ?? 'application/vnd.ms-outlook',
                content: '',
                disposition: AttachmentDisposition::Attachment,
                contentId: $contentId,
                contentLocation: $contentLocation,
                embeddedMessage: $embedded,
            );
        }

        return $attachments;
    }

    /**
     * Parse an embedded-message sub-storage, if present and within the depth limit.
     */
    private function embeddedMessage(OleReader $ole, DirectoryEntry $attachment, int $depth): ?Email
    {
        if (! $this->options->extractNestedMessages || $depth >= $this->options->maxDepth) {
            return null;
        }

        foreach ($ole->children($attachment) as $child) {
            if ($child->isStorage() && strcasecmp($child->name, self::EMBEDDED_MESSAGE_OBJECT) === 0) {
                try {
                    return $this->parseStorage($ole, $child, self::EMBEDDED_MESSAGE_HEADER, $depth + 1);
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    private function addressFrom(
        MapiProperties $props,
        int $smtpTag,
        int $addrTypeTag,
        int $emailTag,
        int $nameTag,
        string $ansiCharset,
    ): ?Address {
        $email = $props->text($smtpTag, $ansiCharset);

        if ($email === null) {
            $addrType = $props->text($addrTypeTag, $ansiCharset);
            $candidate = $props->text($emailTag, $ansiCharset);

            if ($candidate !== null
                && (($addrType !== null && strcasecmp($addrType, 'SMTP') === 0) || str_contains($candidate, '@'))
            ) {
                $email = $candidate;
            }
        }

        $email = $this->normalizeEmail($email);
        $name = $props->text($nameTag, $ansiCharset);

        if ($email === null && ($name === null || $name === '')) {
            return null;
        }

        return new Address($email, $name !== '' ? $name : null);
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        if (preg_match('/^smtp:(.+)$/i', $email, $m) === 1) {
            $email = $m[1];
        }

        $email = trim($email);

        // Reject Exchange X.500 distinguished names; they are not real addresses.
        if ($email === '' || str_starts_with($email, '/')) {
            return null;
        }

        return $email;
    }

    private function isStorage(DirectoryEntry $entry, string $prefix): bool
    {
        return str_starts_with(strtolower($entry->name), strtolower($prefix));
    }

    private function decodedHeader(Headers $headers, string $name): ?string
    {
        $value = $headers->get($name);

        return $value !== null ? MimeHeaderDecoder::decode($value) : null;
    }

    private function messageIdHeader(Headers $headers): ?string
    {
        $value = $headers->get('message-id');

        return $value !== null && trim($value) !== '' ? $value : null;
    }

    /**
     * Ensure the headers bag exposes the core fields even when the message had
     * no transport headers, so callers get consistent behaviour across formats.
     *
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     */
    private function synthesizeHeaders(
        Headers $headers,
        ?string $subject,
        ?Address $from,
        array $to,
        array $cc,
        ?DateTimeImmutable $date,
        ?string $messageId,
    ): Headers {
        $add = static function (string $name, ?string $value) use ($headers): void {
            if ($value !== null && $value !== '' && ! $headers->has($name)) {
                $headers->add($name, $value);
            }
        };

        $add('Subject', $subject);
        $add('From', $from?->toString());
        $add('To', $this->joinAddresses($to));
        $add('Cc', $this->joinAddresses($cc));
        $add('Date', $date?->format(DATE_RFC2822));
        $add('Message-ID', $messageId);

        return $headers;
    }

    /**
     * @param  list<Address>  $addresses
     */
    private function joinAddresses(array $addresses): ?string
    {
        if ($addresses === []) {
            return null;
        }

        return implode(', ', array_map(static fn (Address $a): string => $a->toString(), $addresses));
    }
}
