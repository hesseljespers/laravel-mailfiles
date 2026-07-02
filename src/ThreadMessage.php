<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use DateTimeImmutable;
use Melloww\MailFiles\Enums\ThreadEntryType;

/**
 * One message within a conversation/thread.
 *
 * The newest entry (the message actually being read) is fully accurate; older
 * entries recovered from quoted "forwarded"/"reply" blocks in the body are
 * best-effort and may have only partial contact information.
 */
final class ThreadMessage
{
    /**
     * @param  list<Address>  $to
     * @param  list<Address>  $cc
     */
    public function __construct(
        private readonly ThreadEntryType $type,
        private readonly ?Address $from = null,
        private readonly array $to = [],
        private readonly array $cc = [],
        private readonly ?DateTimeImmutable $date = null,
        private readonly ?string $subject = null,
    ) {}

    public function type(): ThreadEntryType
    {
        return $this->type;
    }

    public function from(): ?Address
    {
        return $this->from;
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

    public function date(): ?DateTimeImmutable
    {
        return $this->date;
    }

    public function subject(): ?string
    {
        return $this->subject;
    }

    /** Whether this is the message being read (as opposed to a quoted ancestor). */
    public function isCurrent(): bool
    {
        return $this->type === ThreadEntryType::Message;
    }

    /**
     * Whether this entry was recovered structurally (the current message or an
     * attached original) rather than heuristically from quoted body text.
     */
    public function isReliable(): bool
    {
        return $this->type === ThreadEntryType::Message || $this->type === ThreadEntryType::Attached;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'from' => $this->from?->toArray(),
            'to' => array_map(static fn (Address $a) => $a->toArray(), $this->to),
            'cc' => array_map(static fn (Address $a) => $a->toArray(), $this->cc),
            'date' => $this->date?->format(DATE_RFC2822),
            'subject' => $this->subject,
        ];
    }
}
