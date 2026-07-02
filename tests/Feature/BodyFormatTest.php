<?php

declare(strict_types=1);

use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\BodyFormat;
use Melloww\MailFiles\Enums\MailFormat;
use Melloww\MailFiles\MailFile;
use Melloww\MailFiles\Support\HtmlToMarkdown;

function htmlEmail(string $html): Email
{
    return new Email(
        format: MailFormat::Eml,
        htmlBody: $html,
    );
}

describe('HtmlToMarkdown', function () {
    it('converts headings, emphasis and links', function () {
        $md = HtmlToMarkdown::convert(
            '<h1>Title</h1><p>Hello <strong>world</strong> and <em>you</em>, see <a href="https://example.com">link</a>.</p>'
        );

        expect($md)->toContain('# Title')
            ->toContain('**world**')
            ->toContain('*you*')
            ->toContain('[link](https://example.com)');
    });

    it('converts unordered and ordered lists', function () {
        expect(HtmlToMarkdown::convert('<ul><li>one</li><li>two</li></ul>'))
            ->toContain('- one')
            ->toContain('- two');

        expect(HtmlToMarkdown::convert('<ol><li>first</li><li>second</li></ol>'))
            ->toContain('1. first')
            ->toContain('2. second');
    });

    it('converts blockquotes and images', function () {
        expect(HtmlToMarkdown::convert('<blockquote>quoted</blockquote>'))->toContain('> quoted');
        expect(HtmlToMarkdown::convert('<img src="cid:logo" alt="Logo">'))->toBe('![Logo](cid:logo)');
    });

    it('drops script and style content', function () {
        $md = HtmlToMarkdown::convert('<p>keep</p><script>alert(1)</script><style>.x{}</style>');

        expect($md)->toContain('keep')
            ->not->toContain('alert')
            ->not->toContain('.x{}');
    });
});

describe('Email body formats', function () {
    it('returns html, text and markdown for an html body', function () {
        $email = htmlEmail('<p>Hello <strong>world</strong></p>');

        expect($email->htmlBody())->toBe('<p>Hello <strong>world</strong></p>')
            ->and($email->markdown())->toContain('**world**')
            ->and($email->body())->toBe('Hello world');
    });

    it('selects the representation via bodyAs()', function () {
        $email = htmlEmail('<p>Hi <em>there</em></p>');

        expect($email->bodyAs(BodyFormat::Html))->toBe('<p>Hi <em>there</em></p>')
            ->and($email->bodyAs(BodyFormat::Text))->toBe('Hi there')
            ->and($email->bodyAs(BodyFormat::Markdown))->toContain('*there*');
    });

    it('falls back to the plain-text body when there is no html', function () {
        $email = new Email(format: MailFormat::Eml, textBody: 'Just text.');

        expect($email->markdown())->toBe('Just text.')
            ->and($email->bodyAs(BodyFormat::Html))->toBeNull();
    });

    it('produces markdown from a real html email', function () {
        $email = MailFile::read(fixture('eml/testRFC822-multipart.eml'));

        expect($email->markdown())->toBeString()->not->toBeEmpty();
    });
});
