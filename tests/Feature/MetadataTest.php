<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\Priority;
use Melloww\MailFiles\Enums\Sensitivity;
use Melloww\MailFiles\Parsers\EmlParser;

function metadataEmail(): Email
{
    $raw = implode("\r\n", [
        'From: sender@example.com',
        'To: rcpt@example.com',
        'Subject: RE: hello',
        'Message-ID: <child@example.com>',
        'In-Reply-To: <parent@example.com>',
        'References: <root@example.com> <parent@example.com>',
        'X-Priority: 1 (Highest)',
        'Importance: high',
        'Sensitivity: Private',
        'Auto-Submitted: auto-replied',
        'Return-Path: <bounce@example.com>',
        'Delivered-To: rcpt@example.com',
        'List-Id: Widgets <widgets.example.com>',
        'List-Unsubscribe: <mailto:unsub@example.com>, <https://example.com/unsub>',
        'Disposition-Notification-To: sender@example.com',
        'Authentication-Results: mx.example.com; spf=pass smtp.mailfrom=example.com; dkim=pass; dmarc=fail',
        'Content-Type: text/plain',
        '',
        'Body.',
    ]);

    return (new EmlParser)->parse($raw);
}

it('reads threading headers', function () {
    $email = metadataEmail();

    expect($email->inReplyTo())->toBe('parent@example.com')
        ->and($email->references())->toBe(['root@example.com', 'parent@example.com']);
});

it('reads priority and sensitivity', function () {
    $email = metadataEmail();

    expect($email->priority())->toBe(Priority::High)
        ->and($email->sensitivity())->toBe(Sensitivity::Private);
});

it('reads delivery and list metadata', function () {
    $email = metadataEmail();

    expect($email->isAutoSubmitted())->toBeTrue()
        ->and($email->returnPath()->email)->toBe('bounce@example.com')
        ->and($email->deliveredTo())->toBe('rcpt@example.com')
        ->and($email->listId())->toBe('widgets.example.com')
        ->and($email->listUnsubscribe())->toBe(['mailto:unsub@example.com', 'https://example.com/unsub']);
});

it('reads read-receipt requests', function () {
    $email = metadataEmail();

    expect($email->wantsReadReceipt())->toBeTrue()
        ->and($email->readReceiptTo()[0]->email)->toBe('sender@example.com');
});

it('parses authentication results', function () {
    $email = metadataEmail();

    expect($email->spfResult())->toBe('pass')
        ->and($email->dkimResult())->toBe('pass')
        ->and($email->dmarcResult())->toBe('fail');
});

it('defaults priority to normal and sensitivity to normal when unset', function () {
    $email = (new EmlParser)->parse("Subject: plain\r\n\r\nbody");

    expect($email->priority())->toBe(Priority::Normal)
        ->and($email->sensitivity())->toBe(Sensitivity::Normal)
        ->and($email->inReplyTo())->toBeNull()
        ->and($email->references())->toBe([])
        ->and($email->isAutoSubmitted())->toBeFalse();
});
