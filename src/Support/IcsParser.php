<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use DateTimeImmutable;
use DateTimeZone;
use Melloww\MailFiles\Address;
use Melloww\MailFiles\CalendarEvent;
use Throwable;

/**
 * Minimal iCalendar (RFC 5545) reader — just enough to describe the meeting
 * invitations/cancellations that arrive as text/calendar mail parts.
 */
final class IcsParser
{
    /**
     * @return list<CalendarEvent>
     */
    public static function parse(string $ics): array
    {
        $lines = self::unfold($ics);

        $method = null;
        $events = [];
        $current = null;

        foreach ($lines as $line) {
            [$name, $params, $value] = self::splitLine($line);

            if ($name === 'METHOD') {
                $method = $value;

                continue;
            }

            if ($name === 'BEGIN' && strtoupper($value) === 'VEVENT') {
                $current = ['attendees' => []];

                continue;
            }

            if ($name === 'END' && strtoupper($value) === 'VEVENT') {
                if ($current !== null) {
                    $events[] = self::makeEvent($method, $current);
                }
                $current = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            switch ($name) {
                case 'UID':
                    $current['uid'] = $value;
                    break;
                case 'SUMMARY':
                    $current['summary'] = self::unescapeText($value);
                    break;
                case 'LOCATION':
                    $current['location'] = self::unescapeText($value);
                    break;
                case 'STATUS':
                    $current['status'] = $value;
                    break;
                case 'DTSTART':
                    $current['start'] = self::parseDate($value);
                    break;
                case 'DTEND':
                    $current['end'] = self::parseDate($value);
                    break;
                case 'ORGANIZER':
                    $current['organizer'] = self::parseMailbox($params, $value);
                    break;
                case 'ATTENDEE':
                    $attendee = self::parseMailbox($params, $value);
                    if ($attendee !== null) {
                        $current['attendees'][] = $attendee;
                    }
                    break;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private static function makeEvent(?string $method, array $event): CalendarEvent
    {
        return new CalendarEvent(
            method: $method,
            uid: $event['uid'] ?? null,
            summary: $event['summary'] ?? null,
            location: $event['location'] ?? null,
            organizer: $event['organizer'] ?? null,
            attendees: $event['attendees'] ?? [],
            start: $event['start'] ?? null,
            end: $event['end'] ?? null,
            status: $event['status'] ?? null,
        );
    }

    /**
     * Unfold RFC 5545 continuation lines (a leading space/tab continues the previous line).
     *
     * @return list<string>
     */
    private static function unfold(string $ics): array
    {
        $raw = preg_split('/\r\n|\r|\n/', $ics) ?: [];
        $lines = [];

        foreach ($raw as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $lines !== []) {
                $lines[count($lines) - 1] .= substr($line, 1);

                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return array{0: string, 1: array<string, string>, 2: string} [name, params, value]
     */
    private static function splitLine(string $line): array
    {
        $colon = strpos($line, ':');
        if ($colon === false) {
            return ['', [], ''];
        }

        $namePart = substr($line, 0, $colon);
        $value = substr($line, $colon + 1);

        $segments = explode(';', $namePart);
        $name = strtoupper(array_shift($segments) ?: '');

        $params = [];
        foreach ($segments as $segment) {
            if (str_contains($segment, '=')) {
                [$k, $v] = explode('=', $segment, 2);
                $params[strtoupper($k)] = trim($v, '"');
            }
        }

        return [$name, $params, $value];
    }

    /**
     * @param  array<string, string>  $params
     */
    private static function parseMailbox(array $params, string $value): ?Address
    {
        $email = preg_replace('/^mailto:/i', '', trim($value)) ?? $value;
        $email = trim($email);
        $name = $params['CN'] ?? null;

        if ($email === '' && ($name === null || $name === '')) {
            return null;
        }

        return new Address($email !== '' ? $email : null, $name !== '' ? $name : null);
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);

        foreach (['Ymd\THis\Z', 'Ymd\THis', 'Ymd'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('UTC'));
            if ($date !== false) {
                return $date;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function unescapeText(string $value): string
    {
        return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value);
    }
}
