<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\MboxReader;

it('streams every message from an mbox spool', function () {
    $emails = iterator_to_array(MailFile::mbox(fixture('mbox/sample.mbox')));

    expect($emails)->toHaveCount(2)
        ->and($emails[0])->toBeInstanceOf(Email::class)
        ->and($emails[0]->subject())->toBe('First message')
        ->and($emails[0]->from()->email)->toBe('alice@example.com')
        ->and($emails[1]->subject())->toBe('Second message')
        ->and($emails[1]->from()->email)->toBe('carol@example.com');
});

it('reverses mboxrd ">From" escaping in the body', function () {
    $emails = iterator_to_array(MailFile::mbox(fixture('mbox/sample.mbox')));

    expect($emails[0]->body())->toContain('From here the quoted line was escaped.')
        ->and($emails[0]->body())->not->toContain('>From here');
});

it('is lazy — the generator yields one message at a time', function () {
    $generator = MailFile::mbox(fixture('mbox/sample.mbox'));

    expect($generator)->toBeInstanceOf(Generator::class);

    $first = $generator->current();
    expect($first->subject())->toBe('First message');
});

it('parses an in-memory mbox string', function () {
    $mbox = "From a@x Mon Jan 01 00:00:00 2024\r\nSubject: One\r\n\r\nBody one.\r\n"
        ."From b@y Tue Jan 02 00:00:00 2024\r\nSubject: Two\r\n\r\nBody two.\r\n";

    $subjects = array_map(fn (Email $e) => $e->subject(), iterator_to_array((new MboxReader)->readString($mbox)));

    expect($subjects)->toBe(['One', 'Two']);
});

it('reads every mail file in a directory keyed by path', function () {
    $emails = iterator_to_array(MailFile::directory(fixture('msg')));

    expect($emails)->not->toBeEmpty();

    foreach ($emails as $path => $email) {
        expect($path)->toEndWith('.msg')
            ->and($email)->toBeInstanceOf(Email::class);
    }
});
