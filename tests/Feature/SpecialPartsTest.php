<?php

declare(strict_types=1);

use Melloww\MailFiles\Parsers\EmlParser;

it('detects an S/MIME signed message and keeps the signed content as the body', function () {
    $raw = implode("\r\n", [
        'From: a@example.com',
        'Subject: signed',
        'Content-Type: multipart/signed; protocol="application/pkcs7-signature"; micalg=sha-256; boundary=SIG',
        '',
        '--SIG',
        'Content-Type: text/plain; charset=UTF-8',
        '',
        'This content is signed.',
        '--SIG',
        'Content-Type: application/pkcs7-signature; name="smime.p7s"',
        'Content-Transfer-Encoding: base64',
        'Content-Disposition: attachment; filename="smime.p7s"',
        '',
        base64_encode('dummy-signature-bytes'),
        '--SIG--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->isSigned())->toBeTrue()
        ->and($email->isEncrypted())->toBeFalse()
        ->and($email->securityProtocol())->toBe('smime')
        ->and($email->body())->toBe('This content is signed.')
        // The signature part is not surfaced as a user attachment.
        ->and($email->attachments())->toBeEmpty();
});

it('detects a PGP/MIME encrypted message', function () {
    $raw = implode("\r\n", [
        'Subject: secret',
        'Content-Type: multipart/encrypted; protocol="application/pgp-encrypted"; boundary=ENC',
        '',
        '--ENC',
        'Content-Type: application/pgp-encrypted',
        '',
        'Version: 1',
        '--ENC',
        'Content-Type: application/octet-stream',
        '',
        '-----BEGIN PGP MESSAGE-----',
        '--ENC--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->isEncrypted())->toBeTrue()
        ->and($email->securityProtocol())->toBe('pgp');
});

it('parses a calendar invitation', function () {
    $ics = implode("\r\n", [
        'BEGIN:VCALENDAR',
        'METHOD:REQUEST',
        'BEGIN:VEVENT',
        'UID:abc-123',
        'SUMMARY:Project kickoff',
        'LOCATION:Room 5',
        'ORGANIZER;CN=Alice Organizer:mailto:alice@example.com',
        'ATTENDEE;CN=Bob:mailto:bob@example.com',
        'DTSTART:20250701T090000Z',
        'DTEND:20250701T100000Z',
        'STATUS:CONFIRMED',
        'END:VEVENT',
        'END:VCALENDAR',
    ]);

    $raw = implode("\r\n", [
        'Subject: Invitation: Project kickoff',
        'Content-Type: multipart/mixed; boundary=MIX',
        '',
        '--MIX',
        'Content-Type: text/plain',
        '',
        'You are invited.',
        '--MIX',
        'Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name="invite.ics"',
        '',
        $ics,
        '--MIX--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->hasCalendar())->toBeTrue();

    $event = $email->calendarEvents()[0];

    expect($event->method())->toBe('REQUEST')
        ->and($event->summary())->toBe('Project kickoff')
        ->and($event->location())->toBe('Room 5')
        ->and($event->organizer()->email)->toBe('alice@example.com')
        ->and($event->organizer()->name)->toBe('Alice Organizer')
        ->and($event->attendees()[0]->email)->toBe('bob@example.com')
        ->and($event->start()->format('Y-m-d H:i'))->toBe('2025-07-01 09:00')
        ->and($event->isCancellation())->toBeFalse();
});

it('detects a delivery status notification (bounce)', function () {
    $raw = implode("\r\n", [
        'From: mailer-daemon@example.com',
        'Subject: Undelivered Mail Returned to Sender',
        'Content-Type: multipart/report; report-type=delivery-status; boundary=RPT',
        '',
        '--RPT',
        'Content-Type: text/plain',
        '',
        'Delivery to the following recipient failed permanently.',
        '--RPT',
        'Content-Type: message/delivery-status',
        '',
        'Reporting-MTA: dns; mx.example.com',
        'Final-Recipient: rfc822; nobody@example.com',
        'Action: failed',
        'Status: 5.1.1',
        '--RPT--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->isDeliveryStatusNotification())->toBeTrue()
        ->and($email->isReadReceipt())->toBeFalse()
        ->and($email->body())->toContain('failed permanently');
});

it('detects a TNEF (winmail.dat) attachment', function () {
    $raw = implode("\r\n", [
        'Subject: with winmail',
        'Content-Type: multipart/mixed; boundary=MIX',
        '',
        '--MIX',
        'Content-Type: text/plain',
        '',
        'See attached.',
        '--MIX',
        'Content-Type: application/ms-tnef; name="winmail.dat"',
        'Content-Transfer-Encoding: base64',
        'Content-Disposition: attachment; filename="winmail.dat"',
        '',
        base64_encode('dummy-tnef'),
        '--MIX--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->hasTnef())->toBeTrue()
        ->and($email->tnefAttachment()->filename())->toBe('winmail.dat');
});
