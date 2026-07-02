<?php

declare(strict_types=1);

use Melloww\MailFiles\Attachment;
use Melloww\MailFiles\Support\FileSize;

it('formats byte counts in binary units', function (int $bytes, string $expected) {
    expect(FileSize::human($bytes))->toBe($expected);
})->with([
    [0, '0 B'],
    [512, '512 B'],
    [1023, '1023 B'],
    [1024, '1.0 KB'],
    [1536, '1.5 KB'],
    [1048576, '1.0 MB'],
    [1335122, '1.3 MB'],
    [5_368_709_120, '5.0 GB'],
]);

it('respects the precision argument', function () {
    expect(FileSize::human(1536, 2))->toBe('1.50 KB')
        ->and(FileSize::human(1335122, 0))->toBe('1 MB');
});

it('exposes a human-readable size on an attachment', function () {
    $attachment = new Attachment(
        filename: 'note.txt',
        contentType: 'text/plain',
        content: str_repeat('x', 2048),
    );

    expect($attachment->size())->toBe(2048)
        ->and($attachment->humanSize())->toBe('2.0 KB');
});
