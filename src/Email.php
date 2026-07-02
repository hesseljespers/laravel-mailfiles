<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use DateTimeImmutable;
use Melloww\MailFiles\Contracts\Message;
use Melloww\MailFiles\Enums\BodyFormat;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Enums\Priority;
use Melloww\MailFiles\Enums\Sensitivity;
use Melloww\MailFiles\Enums\ThreadEntryType;
use Melloww\MailFiles\Support\AddressParser;
use Melloww\MailFiles\Support\HtmlToMarkdown;
use Melloww\MailFiles\Support\IcsParser;
use Melloww\MailFiles\Support\ThreadBuilder;

/**
 * Immutable, format-agnostic representation of a parsed email message.
 *
 * Both the EML and the MSG parsers produce this exact value object, which is
 * what lets callers read every message through one consistent API.
 */
final class Email implements Message
{
    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     * @param  list<Address>  $bcc
     * @param  list<Address>  $replyTo
     * @param  list<Attachment>  $attachments
     */
    public function __construct(
        private readonly MailFormat $format,
        private readonly ?string $subject = null,
        private readonly ?Address $from = null,
        private readonly ?Address $sender = null,
        private readonly array $to = [],
        private readonly array $cc = [],
        private readonly array $bcc = [],
        private readonly array $replyTo = [],
        private readonly ?DateTimeImmutable $date = null,
        private readonly ?string $messageId = null,
        private readonly ?string $textBody = null,
        private readonly ?string $htmlBody = null,
        private readonly array $attachments = [],
        private readonly ?Headers $headers = null,
        private readonly ?string $rtfBody = null,
        private readonly bool $signed = false,
        private readonly bool $encrypted = false,
        private readonly ?string $securityProtocol = null,
        private readonly bool $headersOnly = false,
    ) {}

    /**
     * Whether this message was parsed headers-only (via a metadata-only read).
     * When true, the body, attachments, calendar and nested-message accessors
     * are intentionally empty — reparse the file fully to access them.
     */
    public function isHeadersOnly(): bool
    {
        return $this->headersOnly;
    }

    public function format(): MailFormat
    {
        return $this->format;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    public function from(): ?Address
    {
        return $this->from;
    }

    public function sender(): ?Address
    {
        return $this->sender ?? $this->from;
    }

    /** @return list<Address> */
    public function to(): array
    {
        return $this->to;
    }

    /** @return list<Address> */
    public function cc(): array
    {
        return $this->cc;
    }

    /** @return list<Address> */
    public function bcc(): array
    {
        return $this->bcc;
    }

    /** @return list<Address> */
    public function replyTo(): array
    {
        return $this->replyTo;
    }

    /** @return list<Address> */
    public function recipients(): array
    {
        return [...$this->to, ...$this->cc, ...$this->bcc];
    }

    public function date(): ?DateTimeImmutable
    {
        return $this->date;
    }

    public function messageId(): ?string
    {
        return $this->messageId;
    }

    public function headers(): Headers
    {
        return $this->headers ?? new Headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers?->get($name);
    }

    // ---------------------------------------------------------------------
    //  Metadata
    // ---------------------------------------------------------------------

    /** The Message-ID this message is a direct reply to. */
    public function inReplyTo(): ?string
    {
        $value = $this->header('in-reply-to');

        if ($value === null) {
            return null;
        }

        $value = trim($value, " \t<>");

        return $value === '' ? null : $value;
    }

    /**
     * The Message-IDs of the whole referenced thread (oldest first).
     *
     * @return list<string>
     */
    public function references(): array
    {
        $value = $this->header('references');

        if ($value === null) {
            return [];
        }

        if (preg_match_all('/<([^>]+)>/', $value, $m) === 1 || $m[1] !== []) {
            return $m[1];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($value)) ?: []));
    }

    /** The declared priority/importance (defaults to Normal when unspecified). */
    public function priority(): Priority
    {
        return match (strtolower(trim((string) $this->header('importance')))) {
            'high' => Priority::High,
            'low' => Priority::Low,
            default => $this->priorityFromXHeaders(),
        };
    }

    /** The declared sensitivity/confidentiality (defaults to Normal). */
    public function sensitivity(): Sensitivity
    {
        return match (strtolower(trim((string) $this->header('sensitivity')))) {
            'personal' => Sensitivity::Personal,
            'private' => Sensitivity::Private,
            'company-confidential', 'confidential' => Sensitivity::Confidential,
            default => Sensitivity::Normal,
        };
    }

    /** Whether the message was generated automatically (auto-reply, bulk, list, ...). */
    public function isAutoSubmitted(): bool
    {
        $autoSubmitted = strtolower(trim((string) $this->header('auto-submitted')));

        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        return in_array(strtolower(trim((string) $this->header('precedence'))), ['bulk', 'list', 'junk', 'auto_reply'], true);
    }

    public function returnPath(): ?Address
    {
        $value = $this->header('return-path');

        return $value !== null ? (AddressParser::parseOne($value)) : null;
    }

    public function deliveredTo(): ?string
    {
        return AddressParser::parseOne((string) $this->header('delivered-to'))?->email();
    }

    /**
     * The unsubscribe endpoints from the List-Unsubscribe header (mailto:/https:).
     *
     * @return list<string>
     */
    public function listUnsubscribe(): array
    {
        $value = $this->header('list-unsubscribe');

        if ($value === null) {
            return [];
        }

        preg_match_all('/<([^>]+)>/', $value, $m);

        return $m[1];
    }

    public function listId(): ?string
    {
        $value = $this->header('list-id');

        if ($value === null) {
            return null;
        }

        // Prefer the <bracketed> identifier when present.
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            return $m[1];
        }

        return trim($value) === '' ? null : trim($value);
    }

    /** Whether a read receipt (MDN) was requested. */
    public function wantsReadReceipt(): bool
    {
        return $this->header('disposition-notification-to') !== null
            || $this->header('return-receipt-to') !== null;
    }

    /**
     * The mailbox(es) a read receipt should be sent to, if requested.
     *
     * @return list<Address>
     */
    public function readReceiptTo(): array
    {
        $value = $this->header('disposition-notification-to') ?? $this->header('return-receipt-to');

        return $value !== null ? AddressParser::parseList($value) : [];
    }

    /** The raw Authentication-Results header, if present. */
    public function authenticationResults(): ?string
    {
        return $this->header('authentication-results');
    }

    /** The SPF result (pass, fail, softfail, neutral, none, ...), if determinable. */
    public function spfResult(): ?string
    {
        $result = $this->authMethodResult('spf');

        if ($result !== null) {
            return $result;
        }

        $receivedSpf = $this->header('received-spf');

        return $receivedSpf !== null && preg_match('/^\s*(\w+)/', $receivedSpf, $m) === 1
            ? strtolower($m[1])
            : null;
    }

    /** The DKIM result, if present in Authentication-Results. */
    public function dkimResult(): ?string
    {
        return $this->authMethodResult('dkim');
    }

    /** The DMARC result, if present in Authentication-Results. */
    public function dmarcResult(): ?string
    {
        return $this->authMethodResult('dmarc');
    }

    private function priorityFromXHeaders(): Priority
    {
        $xPriority = trim((string) $this->header('x-priority'));

        if ($xPriority !== '' && preg_match('/\d/', $xPriority, $m) === 1) {
            $level = (int) $m[0];
            if ($level > 0 && $level <= 2) {
                return Priority::High;
            }
            if ($level >= 4) {
                return Priority::Low;
            }
            if ($level === 3) {
                return Priority::Normal;
            }
        }

        return match (strtolower(trim((string) $this->header('x-msmail-priority')))) {
            'high' => Priority::High,
            'low' => Priority::Low,
            default => Priority::Normal,
        };
    }

    private function authMethodResult(string $method): ?string
    {
        $results = $this->header('authentication-results');

        return $results !== null && preg_match('/\b'.preg_quote($method, '/').'=(\w+)/i', $results, $m) === 1
            ? strtolower($m[1])
            : null;
    }

    public function textBody(): ?string
    {
        return $this->textBody;
    }

    public function htmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function hasTextBody(): bool
    {
        return $this->textBody !== null && $this->textBody !== '';
    }

    public function hasHtmlBody(): bool
    {
        return $this->htmlBody !== null && $this->htmlBody !== '';
    }

    /**
     * The raw (decompressed) RTF body, when the source carried one. Only Outlook
     * `.msg` files provide this; it is null for EML.
     */
    public function rtfBody(): ?string
    {
        return $this->rtfBody;
    }

    public function hasRtfBody(): bool
    {
        return $this->rtfBody !== null && $this->rtfBody !== '';
    }

    public function body(): ?string
    {
        if ($this->hasTextBody()) {
            return $this->textBody;
        }

        if ($this->hasHtmlBody()) {
            return $this->htmlToText($this->htmlBody);
        }

        return null;
    }

    /**
     * The HTML body with `<script>`/`<style>` blocks and any remaining tags
     * removed, leaving readable text. Mirrors the "clean html" helper the
     * reference project relied on.
     */
    public function cleanHtml(): ?string
    {
        if (! $this->hasHtmlBody()) {
            return null;
        }

        return $this->htmlToText($this->htmlBody);
    }

    /**
     * The body rendered as Markdown: converted from the HTML body when present,
     * otherwise the plain-text body (which is already valid Markdown).
     */
    public function markdown(): ?string
    {
        if ($this->hasHtmlBody()) {
            return HtmlToMarkdown::convert($this->htmlBody);
        }

        return $this->hasTextBody() ? $this->textBody : null;
    }

    /**
     * Fetch the body in a chosen representation.
     */
    public function bodyAs(BodyFormat $format): ?string
    {
        return match ($format) {
            BodyFormat::Html => $this->htmlBody(),
            BodyFormat::Text => $this->body(),
            BodyFormat::Markdown => $this->markdown(),
        };
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_values(array_filter($this->attachments, static fn (Attachment $a): bool => ! $a->isInline()));
    }

    /** @return list<Attachment> */
    public function inlineAttachments(): array
    {
        return array_values(array_filter($this->attachments, static fn (Attachment $a): bool => $a->isInline()));
    }

    /** @return list<Attachment> */
    public function allAttachments(): array
    {
        return $this->attachments;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments() !== [];
    }

    /**
     * Messages carried as attachments, parsed into their own Email objects.
     *
     * @return list<Email>
     */
    public function embeddedMessages(): array
    {
        $messages = [];

        foreach ($this->attachments as $attachment) {
            if ($attachment->isEmbeddedMessage()) {
                $messages[] = $attachment->embeddedMessage();
            }
        }

        return $messages;
    }

    /**
     * Every attachment in this message and, recursively, in any message it
     * contains. Useful for "extract every file, however deeply nested".
     *
     * @return list<Attachment>
     */
    public function allAttachmentsRecursive(): array
    {
        $all = $this->attachments;

        foreach ($this->embeddedMessages() as $message) {
            array_push($all, ...$message->allAttachmentsRecursive());
        }

        return $all;
    }

    // ---------------------------------------------------------------------
    //  Special parts
    // ---------------------------------------------------------------------

    /** Whether the message is cryptographically signed (S/MIME or PGP). */
    public function isSigned(): bool
    {
        return $this->signed;
    }

    /** Whether the message body is encrypted (S/MIME or PGP). */
    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    /** "smime" or "pgp" when the message is signed/encrypted, otherwise null. */
    public function securityProtocol(): ?string
    {
        return $this->securityProtocol;
    }

    public function hasCalendar(): bool
    {
        return $this->calendarParts() !== [];
    }

    /**
     * Calendar events (meeting invites/cancellations) carried as text/calendar parts.
     *
     * @return list<CalendarEvent>
     */
    public function calendarEvents(): array
    {
        $events = [];

        foreach ($this->calendarParts() as $part) {
            foreach (IcsParser::parse($part->content()) as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** Whether the message is a delivery status notification (a bounce/DSN). */
    public function isDeliveryStatusNotification(): bool
    {
        return $this->hasPartOfType(['message/delivery-status', 'message/global-delivery-status']);
    }

    /** Whether the message is a read receipt (disposition notification / MDN). */
    public function isReadReceipt(): bool
    {
        return $this->hasPartOfType(['message/disposition-notification']);
    }

    /** Whether the message carries a TNEF (winmail.dat) part. */
    public function hasTnef(): bool
    {
        return $this->tnefAttachment() !== null;
    }

    /**
     * The TNEF (winmail.dat) attachment, if present. Its bytes are exposed as-is;
     * decoding the TNEF container itself is out of scope.
     */
    public function tnefAttachment(): ?Attachment
    {
        foreach ($this->attachments as $attachment) {
            $type = strtolower((string) $attachment->contentType());

            if (in_array($type, ['application/ms-tnef', 'application/vnd.ms-tnef', 'application/x-ms-tnef'], true)
                || strtolower((string) $attachment->filename()) === 'winmail.dat'
            ) {
                return $attachment;
            }
        }

        return null;
    }

    /**
     * @return list<Attachment>
     */
    private function calendarParts(): array
    {
        return array_values(array_filter($this->attachments, static function (Attachment $a): bool {
            $type = strtolower((string) $a->contentType());

            return str_starts_with($type, 'text/calendar')
                || str_starts_with($type, 'application/ics')
                || $a->extension() === 'ics';
        }));
    }

    /**
     * @param  list<string>  $types
     */
    private function hasPartOfType(array $types): bool
    {
        foreach ($this->attachments as $attachment) {
            if (in_array(strtolower((string) $attachment->contentType()), $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reconstructed conversation this message belongs to (newest-first):
     * the message itself, then each older message recovered from forwarded/reply
     * quotes in the body and from attached originals.
     */
    public function thread(): Thread
    {
        return ThreadBuilder::build($this);
    }

    /** Whether this message forwards, or quotes a forward of, an earlier message. */
    public function isForwarded(): bool
    {
        // FW/Fwd (en), WG (de), Doorgest (nl), TR (fr), RV (it), VS (pt), ENC (es).
        if ($this->subjectHasPrefix(['fwd', 'fw', 'wg', 'doorgest', 'tr', 'rv', 'vs', 'enc'])) {
            return true;
        }

        $isReply = $this->isReply();

        foreach ($this->thread()->messages() as $message) {
            if ($message->type() === ThreadEntryType::Attached) {
                return true;
            }

            // A quoted header block can belong to a reply too, so it only implies
            // a forward when the message is not itself a reply.
            if ($message->type() === ThreadEntryType::Forwarded && ! $isReply) {
                return true;
            }
        }

        return false;
    }

    /** Whether this message replies to, or quotes a reply to, an earlier message. */
    public function isReply(): bool
    {
        // Re (en/fr/…), Aw (de), Antw (nl), Sv (scandi), Rif (it), Ref.
        if ($this->subjectHasPrefix(['re', 'aw', 'antw', 'sv', 'rif', 'ref', 'res'])) {
            return true;
        }

        foreach ($this->thread()->messages() as $message) {
            if ($message->type() === ThreadEntryType::Reply) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function subjectHasPrefix(array $prefixes): bool
    {
        $subject = mb_strtolower(trim((string) $this->subject));

        if ($subject === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if (preg_match('/^'.preg_quote($prefix, '/').'\s*(?:\[\d+\]|\(\d+\))?\s*:/u', $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The author of the original (oldest) message in the chain — e.g. who first
     * wrote a message that has since been forwarded. Null when there is no
     * recoverable history.
     */
    public function originalSender(): ?Address
    {
        $thread = $this->thread();

        return $thread->hasHistory() ? $thread->original()->from() : null;
    }

    /**
     * The recipients of the original (oldest) message in the chain — e.g. who a
     * now-forwarded message was originally addressed to.
     *
     * @return list<Address>
     */
    public function originalRecipients(): array
    {
        $thread = $this->thread();

        return $thread->hasHistory() ? $thread->original()->to() : [];
    }

    /**
     * Find an inline attachment by its Content-ID (with or without angle brackets).
     */
    public function inlineAttachmentByContentId(string $contentId): ?Attachment
    {
        $needle = trim($contentId, " <>\t");

        foreach ($this->attachments as $attachment) {
            if ($attachment->contentId() !== null && trim($attachment->contentId(), " <>\t") === $needle) {
                return $attachment;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format->value,
            'subject' => $this->subject,
            'from' => $this->from?->toArray(),
            'sender' => $this->sender()?->toArray(),
            'to' => array_map(static fn (Address $a) => $a->toArray(), $this->to),
            'cc' => array_map(static fn (Address $a) => $a->toArray(), $this->cc),
            'bcc' => array_map(static fn (Address $a) => $a->toArray(), $this->bcc),
            'reply_to' => array_map(static fn (Address $a) => $a->toArray(), $this->replyTo),
            'date' => $this->date?->format(DATE_RFC2822),
            'message_id' => $this->messageId,
            'text_body' => $this->textBody,
            'html_body' => $this->htmlBody,
            'attachments' => array_map(static fn (Attachment $a) => $a->toArray(), $this->allAttachments()),
        ];
    }

    private function htmlToText(string $html): string
    {
        // Drop script/style blocks entirely, then turn block-level breaks into newlines.
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        // Table cells collapse to a space so a label and its value in adjacent
        // cells (common in Outlook forward headers) stay on one line.
        $html = preg_replace('#</(td|th)>#i', ' ', $html) ?? $html;
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|tr|h[1-6]|li)>#i', "\n", $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse runs of whitespace while keeping paragraph breaks readable.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n[ \t]*\n[ \t]*(\n)+/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
