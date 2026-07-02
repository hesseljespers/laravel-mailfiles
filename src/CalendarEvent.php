<?php

declare(strict_types=1);

namespace Melloww\MailFiles;

use DateTimeImmutable;

/**
 * A calendar event (VEVENT) recovered from a text/calendar (.ics) part, such as
 * a meeting invitation or cancellation carried by the message.
 */
final class CalendarEvent
{
    /**
     * @param  list<Address>  $attendees
     */
    public function __construct(
        private readonly ?string $method,
        private readonly ?string $uid,
        private readonly ?string $summary,
        private readonly ?string $location,
        private readonly ?Address $organizer,
        private readonly array $attendees,
        private readonly ?DateTimeImmutable $start,
        private readonly ?DateTimeImmutable $end,
        private readonly ?string $status,
    ) {}

    /** REQUEST, CANCEL, REPLY, PUBLISH, ... (from the VCALENDAR METHOD). */
    public function method(): ?string
    {
        return $this->method;
    }

    public function uid(): ?string
    {
        return $this->uid;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    public function location(): ?string
    {
        return $this->location;
    }

    public function organizer(): ?Address
    {
        return $this->organizer;
    }

    /** @return list<Address> */
    public function attendees(): array
    {
        return $this->attendees;
    }

    public function start(): ?DateTimeImmutable
    {
        return $this->start;
    }

    public function end(): ?DateTimeImmutable
    {
        return $this->end;
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function isCancellation(): bool
    {
        return strtoupper((string) $this->method) === 'CANCEL';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'uid' => $this->uid,
            'summary' => $this->summary,
            'location' => $this->location,
            'organizer' => $this->organizer?->toArray(),
            'attendees' => array_map(static fn (Address $a) => $a->toArray(), $this->attendees),
            'start' => $this->start?->format(DATE_RFC3339),
            'end' => $this->end?->format(DATE_RFC3339),
            'status' => $this->status,
        ];
    }
}
