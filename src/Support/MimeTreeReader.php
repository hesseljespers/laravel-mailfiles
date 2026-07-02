<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Address;
use Melloww\MailFiles\Attachment;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Headers;
use Melloww\MailFiles\ParseOptions;

/**
 * Turns a parsed MIME tree (a root {@see MimePart} with children) into the
 * unified {@see Email} value object.
 *
 * This is the shared back-end for both EML drivers — the native parser and the
 * optional mailparse-backed one only differ in how they build the tree.
 */
final class MimeTreeReader
{
    private ?string $textBody = null;

    private ?string $htmlBody = null;

    /** @var list<Attachment> */
    private array $attachments = [];

    /** @var list<array{part: MimePart, related: bool}> parts awaiting classification once the body is known */
    private array $pending = [];

    private ParseOptions $options;

    private int $depth = 0;

    /** @var array<int, true> object ids of the parts chosen as the message body */
    private array $bodyPartIds = [];

    public function toEmail(MimePart $root, ParseOptions $options = new ParseOptions, int $depth = 0): Email
    {
        $this->textBody = null;
        $this->htmlBody = null;
        $this->attachments = [];
        $this->pending = [];
        $this->bodyPartIds = [];
        $this->options = $options;
        $this->depth = $depth;

        // Select the body honouring MIME multipart semantics (alternative =>
        // richest representation, related => its root, mixed/signed => first
        // body-bearing part), and remember which parts became the body.
        $body = $this->selectBody($root);
        $this->textBody = $body['text']?->decodedText();
        $this->htmlBody = $body['html']?->decodedText();
        foreach ([$body['text'], $body['html']] as $bodyPart) {
            if ($bodyPart !== null) {
                $this->bodyPartIds[spl_object_id($bodyPart)] = true;
            }
        }

        $security = $this->detectSecurity($root);

        // Everything that is not the body is a candidate attachment. Classify
        // once the HTML body is known (so cid references can be resolved).
        $this->walk($root, false);
        $this->buildAttachments();

        $headers = $root->headers;

        return new Email(
            format: MailFormat::Eml,
            subject: $this->decodedHeader($headers, 'subject'),
            from: $this->firstAddress($headers->get('from')),
            sender: $this->firstAddress($headers->get('sender')),
            to: AddressParser::parseList($headers->get('to')),
            cc: AddressParser::parseList($headers->get('cc')),
            bcc: AddressParser::parseList($headers->get('bcc')),
            replyTo: AddressParser::parseList($headers->get('reply-to')),
            date: DateParser::parse($headers->get('date')),
            messageId: $this->messageId($headers),
            textBody: $this->textBody,
            htmlBody: $this->htmlBody,
            attachments: $this->attachments,
            headers: $headers,
            signed: $security['signed'],
            encrypted: $security['encrypted'],
            securityProtocol: $security['protocol'],
        );
    }

    private function walk(MimePart $part, bool $insideRelated): void
    {
        if ($part->isMultipart() && $part->children !== []) {
            $childrenRelated = $insideRelated || $part->contentType() === 'multipart/related';

            foreach ($part->children as $child) {
                $this->walk($child, $childrenRelated);
            }

            return;
        }

        // A leaf that was chosen as the body is not an attachment.
        if (! isset($this->bodyPartIds[spl_object_id($part)])) {
            $this->pending[] = ['part' => $part, 'related' => $insideRelated];
        }
    }

    /**
     * Choose the text and HTML body parts from a MIME subtree.
     *
     * @return array{text: ?MimePart, html: ?MimePart}
     */
    private function selectBody(MimePart $part): array
    {
        if ($part->isMultipart() && $part->children !== []) {
            $subtype = substr($part->contentType(), strlen('multipart/'));

            if ($subtype === 'alternative') {
                return $this->selectAlternative($part);
            }

            if ($subtype === 'related') {
                // The body is the "root" resource — the first child. The rest are
                // inline resources it references.
                return $this->selectBody($part->children[0]);
            }

            // mixed / signed / report / digest / ...: the body is the first
            // child that yields one; later children are attachments.
            foreach ($part->children as $child) {
                $result = $this->selectBody($child);
                if ($result['text'] !== null || $result['html'] !== null) {
                    return $result;
                }
            }

            return ['text' => null, 'html' => null];
        }

        if ($this->isBodyLeaf($part)) {
            return $part->contentType() === 'text/html'
                ? ['text' => null, 'html' => $part]
                : ['text' => $part, 'html' => null];
        }

        return ['text' => null, 'html' => null];
    }

    /**
     * Within a multipart/alternative, keep the richest representation of each
     * kind — the later a sibling appears, the richer it is per RFC 2046.
     *
     * @return array{text: ?MimePart, html: ?MimePart}
     */
    private function selectAlternative(MimePart $part): array
    {
        $text = null;
        $html = null;

        foreach ($part->children as $child) {
            $result = $this->selectBody($child);
            $text = $result['text'] ?? $text;
            $html = $result['html'] ?? $html;
        }

        return ['text' => $text, 'html' => $html];
    }

    private function isBodyLeaf(MimePart $part): bool
    {
        $type = $part->contentType();

        if ($type !== 'text/plain' && $type !== 'text/html') {
            return false;
        }

        $disposition = $part->disposition();

        if ($disposition === 'attachment') {
            return false;
        }

        // A named text part with no inline disposition is a genuine attachment.
        return ! ($part->filename() !== null && $disposition !== 'inline');
    }

    /**
     * Detect S/MIME or PGP signing/encryption from the root content type.
     *
     * @return array{signed: bool, encrypted: bool, protocol: ?string}
     */
    private function detectSecurity(MimePart $root): array
    {
        $type = $root->contentType();

        if ($type === 'multipart/signed') {
            $protocol = str_contains(strtolower((string) $root->contentTypeParam('protocol')), 'pgp') ? 'pgp' : 'smime';

            return ['signed' => true, 'encrypted' => false, 'protocol' => $protocol];
        }

        if ($type === 'multipart/encrypted') {
            return ['signed' => false, 'encrypted' => true, 'protocol' => 'pgp'];
        }

        if ($type === 'application/pkcs7-mime' || $type === 'application/x-pkcs7-mime') {
            $smimeType = strtolower((string) $root->contentTypeParam('smime-type'));
            $encrypted = str_contains($smimeType, 'enveloped') || $smimeType === '';

            return ['signed' => ! $encrypted, 'encrypted' => $encrypted, 'protocol' => 'smime'];
        }

        return ['signed' => false, 'encrypted' => false, 'protocol' => null];
    }

    private function buildAttachments(): void
    {
        foreach ($this->pending as ['part' => $part, 'related' => $insideRelated]) {
            $type = $part->contentType();

            // Cryptographic signature parts are not user attachments.
            if (in_array($type, ['application/pkcs7-signature', 'application/x-pkcs7-signature', 'application/pgp-signature'], true)) {
                continue;
            }

            $contentId = $part->contentId();
            $contentLocation = $part->contentLocation();

            $disposition = AttachmentClassifier::classify(
                htmlBody: $this->htmlBody,
                contentId: $contentId,
                contentLocation: $contentLocation,
                explicitInline: $part->disposition() === 'inline',
                explicitAttachment: $part->disposition() === 'attachment',
                insideRelated: $insideRelated,
                isImage: str_starts_with($type, 'image/'),
            );

            $filename = $this->resolveFilename($part);
            // Capture by value: the shared reader instance may be reused for
            // another message before these lazy closures are invoked.
            $options = $this->options;
            $depth = $this->depth;

            $this->attachments[] = new Attachment(
                filename: $filename,
                contentType: $type,
                content: static fn (): string => $part->decodedContent(),
                disposition: $disposition,
                contentId: $contentId,
                contentLocation: $contentLocation,
                embeddedMessage: static fn (): ?Email => NestedMessageResolver::resolve($part->decodedContent(), $type, $filename, $options, $depth),
            );
        }
    }

    private function resolveFilename(MimePart $part): ?string
    {
        $filename = $part->filename();

        if ($filename !== null) {
            return $filename;
        }

        return $part->isRfc822() ? 'attached-message.eml' : null;
    }

    private function firstAddress(?string $headerValue): ?Address
    {
        return AddressParser::parseList($headerValue)[0] ?? null;
    }

    private function decodedHeader(Headers $headers, string $name): ?string
    {
        $value = $headers->get($name);

        return $value !== null ? MimeHeaderDecoder::decode($value) : null;
    }

    private function messageId(Headers $headers): ?string
    {
        $value = $headers->get('message-id');

        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t<>");

        return $value === '' ? null : $value;
    }
}
