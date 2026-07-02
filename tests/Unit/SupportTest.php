<?php

declare(strict_types=1);

use Melloww\MailFiles\Support\AddressParser;
use Melloww\MailFiles\Support\Charset;
use Melloww\MailFiles\Support\ContentDecoder;
use Melloww\MailFiles\Support\DateParser;
use Melloww\MailFiles\Support\HeaderValue;
use Melloww\MailFiles\Support\MimeHeaderDecoder;

describe('MimeHeaderDecoder', function () {
    it('decodes a Q-encoded word', function () {
        expect(MimeHeaderDecoder::decode('=?ISO-8859-1?Q?Mail_avec_fichier_attach=E9?='))
            ->toBe('Mail avec fichier attaché');
    });

    it('decodes a B-encoded word', function () {
        $encoded = '=?UTF-8?B?'.base64_encode('Müller €').'?=';

        expect(MimeHeaderDecoder::decode($encoded))->toBe('Müller €');
    });

    it('joins adjacent encoded words without the separating whitespace', function () {
        $a = '=?UTF-8?B?'.base64_encode('Hello ').'?=';
        $b = '=?UTF-8?B?'.base64_encode('World').'?=';

        expect(MimeHeaderDecoder::decode("$a\r\n $b"))->toBe('Hello World');
    });

    it('leaves plain text untouched', function () {
        expect(MimeHeaderDecoder::decode('Just a subject'))->toBe('Just a subject');
    });
});

describe('AddressParser', function () {
    it('parses a name and address', function () {
        $addresses = AddressParser::parseList('John Doe <john@example.com>');

        expect($addresses)->toHaveCount(1)
            ->and($addresses[0]->name)->toBe('John Doe')
            ->and($addresses[0]->email)->toBe('john@example.com');
    });

    it('keeps commas inside quoted display names', function () {
        $addresses = AddressParser::parseList('"Doe, John" <john@example.com>, jane@example.com');

        expect($addresses)->toHaveCount(2)
            ->and($addresses[0]->name)->toBe('Doe, John')
            ->and($addresses[0]->email)->toBe('john@example.com')
            ->and($addresses[1]->email)->toBe('jane@example.com');
    });

    it('splits semicolon separated lists (Outlook style)', function () {
        $addresses = AddressParser::parseList('a@example.com; b@example.com');

        expect($addresses)->toHaveCount(2);
    });

    it('decodes encoded-word display names', function () {
        $addresses = AddressParser::parseList('=?UTF-8?Q?Andr=C3=A9?= <andre@example.com>');

        expect($addresses[0]->name)->toBe('André');
    });

    it('strips an SMTP: prefix', function () {
        $addresses = AddressParser::parseList('SMTP:user@example.com');

        expect($addresses[0]->email)->toBe('user@example.com');
    });
});

describe('HeaderValue', function () {
    it('parses the value and parameters', function () {
        $parsed = HeaderValue::parse('multipart/mixed; boundary="abc123"; charset=utf-8');

        expect($parsed->value)->toBe('multipart/mixed')
            ->and($parsed->param('boundary'))->toBe('abc123')
            ->and($parsed->param('charset'))->toBe('utf-8');
    });

    it('decodes an RFC 2231 extended parameter', function () {
        $parsed = HeaderValue::parse("attachment; filename*=UTF-8''%E2%82%AC-invoice.pdf");

        expect($parsed->value)->toBe('attachment')
            ->and($parsed->param('filename'))->toBe('€-invoice.pdf');
    });

    it('reassembles RFC 2231 continuation sections', function () {
        $parsed = HeaderValue::parse('attachment; filename*0="long-"; filename*1="name.txt"');

        expect($parsed->param('filename'))->toBe('long-name.txt');
    });
});

describe('ContentDecoder', function () {
    it('decodes base64', function () {
        expect(ContentDecoder::decode(base64_encode('hello'), 'base64'))->toBe('hello');
    });

    it('decodes quoted-printable', function () {
        expect(ContentDecoder::decode('voil=C3=A0', 'quoted-printable'))->toBe('voilà');
    });

    it('passes through 7bit content', function () {
        expect(ContentDecoder::decode('plain', '7bit'))->toBe('plain');
    });
});

describe('Charset', function () {
    it('converts ISO-8859-1 to UTF-8', function () {
        expect(Charset::toUtf8("caf\xe9", 'ISO-8859-1'))->toBe('café');
    });

    it('maps a Windows code page id to a label', function () {
        expect(Charset::fromCodepage(65001))->toBe('UTF-8')
            ->and(Charset::fromCodepage(1252))->toBe('Windows-1252')
            ->and(Charset::fromCodepage(950))->toBe('Big5');
    });
});

describe('DateParser', function () {
    it('parses an RFC 2822 date with a trailing timezone name', function () {
        $date = DateParser::parse('Sun, 16 Jun 2013 17:50:12 +0200 (CEST)');

        expect($date)->not->toBeNull()
            ->and($date->format('Y-m-d H:i'))->toBe('2013-06-16 17:50');
    });

    it('returns null for unparseable input', function () {
        expect(DateParser::parse('not a date at all !!!'))->toBeNull();
    });
});
