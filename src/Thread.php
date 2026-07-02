<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use Melloww\MailFiles\Enums\ThreadEntryType;

/**
 * The reconstructed conversation a message belongs to, ordered newest-first:
 * index 0 is the message being read, followed by each older message recovered
 * from forwarded/reply quotes and attached originals.
 */
final class Thread
{
    /**
     * @param  list<ThreadMessage>  $messages  newest-first
     */
    public function __construct(private readonly array $messages) {}

    /** @return list<ThreadMessage> */
    public function messages(): array
    {
        return $this->messages;
    }

    public function count(): int
    {
        return count($this->messages);
    }

    /** Whether there is any recovered history beyond the message itself. */
    public function hasHistory(): bool
    {
        return count($this->messages) > 1;
    }

    /** The message being read (newest). */
    public function current(): ThreadMessage
    {
        return $this->messages[0];
    }

    /** The oldest recovered message in the chain (the "original"). */
    public function original(): ThreadMessage
    {
        return $this->messages[array_key_last($this->messages)];
    }

    public function isForwarded(): bool
    {
        return $this->hasType(ThreadEntryType::Forwarded) || $this->hasType(ThreadEntryType::Attached);
    }

    public function isReply(): bool
    {
        return $this->hasType(ThreadEntryType::Reply);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (ThreadMessage $m) => $m->toArray(), $this->messages);
    }

    private function hasType(ThreadEntryType $type): bool
    {
        foreach ($this->messages as $message) {
            if ($message->type() === $type) {
                return true;
            }
        }

        return false;
    }
}
