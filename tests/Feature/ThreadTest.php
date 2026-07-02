<?php

declare(strict_types=1);

use Melloww\MailFiles\Enums\ThreadEntryType;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\Parsers\EmlParser;

it('reconstructs the chain from a forwarded message', function () {
    $email = MailFile::read(fixture('eml/forwarded-gmail.eml'));

    $thread = $email->thread();

    expect($email->isForwarded())->toBeTrue()
        ->and($email->isReply())->toBeFalse()
        ->and($thread->count())->toBe(2);

    // Newest entry = the message being read.
    expect($thread->current()->from()->email)->toBe('bob@example.com')
        ->and($thread->current()->to()[0]->email)->toBe('carol@example.com');

    // Oldest entry = the original that was forwarded.
    $original = $thread->original();
    expect($original->type())->toBe(ThreadEntryType::Forwarded)
        ->and($original->from()->email)->toBe('alice@origin.example')
        ->and($original->to()[0]->email)->toBe('bob@example.com')
        ->and($original->cc()[0]->email)->toBe('dan@example.com')
        ->and($original->subject())->toBe('Project update');
});

it('exposes the original sender and recipients directly', function () {
    $email = MailFile::read(fixture('eml/forwarded-gmail.eml'));

    expect($email->originalSender()->email)->toBe('alice@origin.example')
        ->and($email->originalSender()->name)->toBe('Alice Original')
        ->and(array_map(fn ($a) => $a->email, $email->originalRecipients()))->toBe(['bob@example.com']);

    // The address it was forwarded *to* is simply the current recipient.
    expect($email->to()[0]->email)->toBe('carol@example.com');
});

it('recovers the quoted message from an Outlook-style reply', function () {
    $email = MailFile::read(fixture('eml/reply-outlook.eml'));

    expect($email->isReply())->toBeTrue()
        ->and($email->isForwarded())->toBeFalse()
        ->and($email->thread()->count())->toBe(2)
        ->and($email->originalSender()->email)->toBe('bob@example.com')
        ->and($email->originalRecipients()[0]->email)->toBe('carol@example.com');
});

it('detects a non-English (Dutch) reply attribution', function () {
    $email = MailFile::read(fixture('eml/reply-dutch.eml'));

    expect($email->isReply())->toBeTrue()
        ->and($email->thread()->count())->toBe(2)
        ->and($email->thread()->original()->type())->toBe(ThreadEntryType::Reply)
        ->and($email->originalSender()->email)->toBe('piet@voorbeeld.be');
});

it('treats a forwarded-as-attachment original as a structural chain entry', function () {
    // The outer eml carries the original as a message/rfc822 attachment.
    $inner = implode("\r\n", [
        'From: Alice <alice@origin.example>',
        'To: Bob <bob@example.com>',
        'Subject: Original',
        'Content-Type: text/plain',
        '',
        'Body.',
    ]);
    $raw = implode("\r\n", [
        'From: Bob <bob@example.com>',
        'To: Carol <carol@example.com>',
        'Subject: Fwd: Original',
        'Content-Type: multipart/mixed; boundary="B"',
        '',
        '--B',
        'Content-Type: text/plain',
        '',
        'See attached.',
        '--B',
        'Content-Type: message/rfc822',
        'Content-Disposition: attachment',
        '',
        $inner,
        '--B--',
        '',
    ]);

    $email = (new EmlParser)->parse($raw);
    $original = $email->thread()->original();

    expect($email->isForwarded())->toBeTrue()
        ->and($original->type())->toBe(ThreadEntryType::Attached)
        ->and($original->from()->email)->toBe('alice@origin.example')
        ->and($original->to()[0]->email)->toBe('bob@example.com')
        ->and($original->isReliable())->toBeTrue();
});

it('returns just the message itself when there is no history', function () {
    $email = MailFile::read(fixture('eml/m0012.eml'));

    expect($email->thread()->count())->toBe(1)
        ->and($email->thread()->hasHistory())->toBeFalse()
        ->and($email->originalSender())->toBeNull()
        ->and($email->originalRecipients())->toBe([]);
});

it('recovers the original sender from a forwarding header when the body has no quote', function () {
    $raw = implode("\r\n", [
        'From: relay@example.com',
        'To: dest@example.com',
        'Subject: Auto forward',
        'X-Original-Sender: Real Person <real@origin.example>',
        'Content-Type: text/plain',
        '',
        'Forwarded automatically.',
    ]);

    $email = (new EmlParser)->parse($raw);

    expect($email->originalSender()->email)->toBe('real@origin.example');
});
