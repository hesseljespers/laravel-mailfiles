<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Contracts;

use DateTimeImmutable;
use Melloww\MailFiles\Address;
use Melloww\MailFiles\Enums\BodyFormat;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Enums\Priority;
use Melloww\MailFiles\Enums\Sensitivity;
use Melloww\MailFiles\Headers;
use Melloww\MailFiles\Thread;

/**
 * The unified read API exposed for every supported mail file, regardless of
 * whether it originated from an EML or an MSG file.
 */
interface Message
{
    /** Which container format the message was read from. */
    public function format(): MailFormat;

    public function subject(): ?string;

    /** The author of the message (the From header / PR_SENDER). */
    public function from(): ?Address;

    /**
     * The mailbox that actually submitted the message. For most messages this
     * equals {@see from()}; it differs when a message is sent "on behalf of".
     */
    public function sender(): ?Address;

    /** @return list<Address> */
    public function to(): array;

    /** @return list<Address> */
    public function cc(): array;

    /** @return list<Address> */
    public function bcc(): array;

    /** @return list<Address> */
    public function replyTo(): array;

    /** Every recipient across To, Cc and Bcc. @return list<Address> */
    public function recipients(): array;

    /** The date the message was sent. */
    public function date(): ?DateTimeImmutable;

    public function messageId(): ?string;

    /** The Message-ID this message directly replies to. */
    public function inReplyTo(): ?string;

    /** Message-IDs of the referenced thread. @return list<string> */
    public function references(): array;

    public function priority(): Priority;

    public function sensitivity(): Sensitivity;

    public function headers(): Headers;

    /** The first value of a single header, case-insensitively. */
    public function header(string $name): ?string;

    public function textBody(): ?string;

    public function htmlBody(): ?string;

    /**
     * The most useful textual representation: the plain-text body when present,
     * otherwise a tag-stripped version of the HTML body.
     */
    public function body(): ?string;

    /** The body rendered as Markdown. */
    public function markdown(): ?string;

    /** The body in a chosen representation (HTML, plain text or Markdown). */
    public function bodyAs(BodyFormat $format): ?string;

    /** Regular (non-inline) attachments. @return list<\Melloww\MailFiles\Attachment> */
    public function attachments(): array;

    /** Inline / embedded parts such as images referenced from the HTML body. @return list<\Melloww\MailFiles\Attachment> */
    public function inlineAttachments(): array;

    /** Every attachment, inline or not. @return list<\Melloww\MailFiles\Attachment> */
    public function allAttachments(): array;

    public function hasAttachments(): bool;

    /** Messages carried as attachments, parsed recursively. @return list<\Melloww\MailFiles\Email> */
    public function embeddedMessages(): array;

    /** The reconstructed conversation this message belongs to (newest-first). */
    public function thread(): Thread;

    public function isForwarded(): bool;

    public function isReply(): bool;

    /** The author of the oldest message in the chain, if recoverable. */
    public function originalSender(): ?Address;

    /** The recipients of the oldest message in the chain. @return list<Address> */
    public function originalRecipients(): array;
}
