<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\Exceptions\MailFileException;
use Melloww\MailFiles\Facades\MailFiles;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\MailFileReader;

it('auto-detects and reads an eml file', function () {
    $email = MailFile::read(fixture('eml/m0012.eml'));

    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->format())->toBe(MailFormat::Eml)
        ->and($email->subject())->toBe('Hello World !');
});

it('auto-detects and reads a msg file', function () {
    $email = MailFile::read(fixture('msg/testMSG.msg'));

    expect($email->format())->toBe(MailFormat::Msg)
        ->and($email->subject())->toBe('MIME registry use cases');
});

it('detects the format from raw bytes', function () {
    expect(MailFile::detectFormat(file_get_contents(fixture('msg/testMSG.msg'))))->toBe(MailFormat::Msg)
        ->and(MailFile::detectFormat(file_get_contents(fixture('eml/m0012.eml'))))->toBe(MailFormat::Eml);
});

it('exposes the same unified API for both formats', function (string $fixture) {
    $email = MailFile::read(fixture($fixture));

    // Every accessor resolves without caring about the underlying format.
    expect($email->subject())->toBeString()
        ->and($email->to())->toBeArray()
        ->and($email->headers()->toArray())->toBeArray()
        ->and($email->allAttachments())->toBeArray()
        ->and($email->toArray())->toHaveKeys(['format', 'subject', 'from', 'to', 'attachments']);
})->with([
    'eml/testRFC822-multipart.eml',
    'msg/testMSG.msg',
]);

it('throws for a missing file', function () {
    MailFile::read('/does/not/exist.eml');
})->throws(MailFileException::class);

it('throws for empty input', function () {
    (new MailFileReader)->readString('');
})->throws(MailFileException::class);

it('resolves a configured reader through the facade', function () {
    $email = MailFiles::read(fixture('eml/m0012.eml'));

    expect($email->subject())->toBe('Hello World !');
});

it('binds the reader as a container singleton', function () {
    expect(app(MailFileReader::class))->toBe(app(MailFileReader::class));
});
