# Laravel MailFiles — one API for `.eml` and `.msg`

[![Latest Version on Packagist](https://img.shields.io/packagist/v/melloww/laravel-mailfiles.svg?style=flat-square)](https://packagist.org/packages/melloww/laravel-mailfiles)
[![Tests](https://img.shields.io/github/actions/workflow/status/melloww/laravel-mailfiles/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/melloww/laravel-mailfiles/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/melloww/laravel-mailfiles.svg?style=flat-square)](https://packagist.org/packages/melloww/laravel-mailfiles)

Read **RFC 822 `.eml`** files and **Outlook `.msg`** (MAPI) files through a single,
consistent, read-only API. Subject, sender, recipients, dates, text/HTML bodies and
attachments all come back the same way no matter which format you started with.

```php
use Melloww\MailFiles\MailFile;

$email = MailFile::read('/path/to/message.msg'); // or message.eml — auto-detected

$email->subject();        // "Quarterly report"
$email->from();           // Address { name: "Jane Doe", email: "jane@acme.test" }
$email->to();             // list<Address>
$email->date();           // DateTimeImmutable
$email->htmlBody();       // "<p>…</p>"
$email->attachments();    // list<Attachment>
```

## Why this package

To handle support for both the .eml and .msg format, I often found myself duck taping together
several parsers for either format to read the email properly depending on whether it was a .eml from a Mac client or a .msg from an Outlook inbox on Windows. To streamline this process, this package can hopefully fill that need for others as well.

- **One API, two formats.** Both parsers emit the same immutable `Email` value object.
- **No external mail-parsing libraries.** The `.msg` reader is a from-scratch
  pure-PHP OLE2 / MAPI reader; the `.eml` reader is a from-scratch MIME parser.
  Neither `php-mime-mail-parser` nor `hfig/mapi` is pulled in.
- **No required PHP extension beyond `mbstring`.** The `mailparse` extension is
  *optional* — see [EML drivers](#eml-drivers).
- **Framework-agnostic core.** Works fine outside Laravel; the Laravel layer just
  adds a facade, container binding and an Artisan command.

## Installation

```bash
composer require melloww/laravel-mailfiles
```

The service provider and `MailFiles` facade are auto-discovered. Optionally publish
the config file:

```bash
php artisan vendor:publish --tag="mailfiles-config"
```

Requires PHP 8.2+ and the `mbstring` extension (`iconv` is used as a fallback for
exotic charsets when present).

## Reading a message

```php
use Melloww\MailFiles\MailFile;          // static helper (works anywhere)
use Melloww\MailFiles\Facades\MailFiles; // Laravel facade (container-managed)

$email = MailFile::read($path);                 // detect format from the bytes
$email = MailFile::fromString($contents);       // from an in-memory string
$email = MailFile::eml($path);                   // force the EML parser
$email = MailFile::msg($path);                   // force the MSG parser

$email = MailFiles::read($path);                 // same thing, via the facade
$email = MailFiles::readUploadedFile($request->file('mail')); // Laravel upload
```

Format detection is based on file **content** (the OLE2 signature), not the
extension, so a mislabelled file still reads correctly.

## The unified API

Every accessor below works identically for `.eml` and `.msg`.

### Envelope

| Method | Returns | Notes |
| --- | --- | --- |
| `format()` | `MailFormat` enum | `MailFormat::Eml` or `MailFormat::Msg` |
| `subject()` | `?string` | RFC 2047 encoded-words decoded to UTF-8 |
| `from()` | `?Address` | the author of the message |
| `sender()` | `?Address` | the actual submitter; falls back to `from()` |
| `to()` | `list<Address>` | |
| `cc()` | `list<Address>` | |
| `bcc()` | `list<Address>` | |
| `replyTo()` | `list<Address>` | |
| `recipients()` | `list<Address>` | To + Cc + Bcc combined |
| `date()` | `?DateTimeImmutable` | when the message was sent |
| `messageId()` | `?string` | without the surrounding `< >` |

### Metadata

Beyond the envelope, richer metadata is first-class (and works for `.msg` too —
MAPI priority/sensitivity/threading properties are surfaced through the same API):

```php
$email->inReplyTo();      // parent Message-ID
$email->references();     // list<string> of the thread's Message-IDs
$email->priority();       // Priority::High | Normal | Low
$email->sensitivity();    // Sensitivity::Normal | Personal | Private | Confidential
$email->isAutoSubmitted();// auto-reply / bulk / list (for out-of-office notices)
$email->returnPath();     // ?Address
$email->deliveredTo();    // ?string
$email->listId();         // ?string
$email->listUnsubscribe();// list<string> (mailto:/https: endpoints)
$email->wantsReadReceipt();
$email->readReceiptTo();  // list<Address>
$email->authenticationResults();
$email->spfResult();      // "pass" | "fail" | "softfail" | ...
$email->dkimResult();
$email->dmarcResult();
```

### Bodies

| Method | Returns | Notes |
| --- | --- | --- |
| `textBody()` | `?string` | the `text/plain` part, UTF-8 |
| `htmlBody()` | `?string` | the `text/html` part, UTF-8 |
| `body()` | `?string` | text body, or the HTML stripped to text |
| `markdown()` | `?string` | the HTML body converted to Markdown (or the text body) |
| `cleanHtml()` | `?string` | HTML with scripts/styles/tags removed |
| `bodyAs(BodyFormat $f)` | `?string` | the body as `Html`, `Text` or `Markdown` |
| `rtfBody()` | `?string` | the decompressed RTF body (`.msg` only) |
| `hasTextBody()` / `hasHtmlBody()` / `hasRtfBody()` | `bool` | |

For Outlook `.msg` files that carry only a compressed-RTF body, the reader
decompresses it (MS-OXRTFCP) and, when the RTF encapsulates HTML, de-encapsulates
that HTML into `htmlBody()`; otherwise it falls back to text. `rtfBody()` always
exposes the raw decompressed RTF.

```php
use Melloww\MailFiles\Enums\BodyFormat;

$email->htmlBody();                    // raw HTML
$email->body();                        // plain text (text part, or HTML stripped)
$email->markdown();                    // "# Heading\n\nHello **world** …"
$email->bodyAs(BodyFormat::Markdown);  // same, choosing the format dynamically
```

The HTML→Markdown conversion is built in (no dependency); it uses the bundled
`dom` extension when available and degrades to plain text otherwise.

### Attachments

```php
foreach ($email->attachments() as $attachment) {
    $attachment->filename();        // "invoice.pdf"
    $attachment->contentType();     // "application/pdf" — as declared (may be null)
    $attachment->mimeType();        // declared type, else detected from the bytes.
    $attachment->extension();       // "pdf"
    $attachment->size();            // 48213 (bytes)
    $attachment->humanSize();       // "47.1 KB"
    $attachment->disposition();     // AttachmentDisposition::Attachment
    $attachment->isInline();        // false
    $attachment->isEmbeddedImage(); // false
    $attachment->contentId();       // null, or the cid for inline images
    $attachment->content();         // the raw decoded bytes
    $attachment->saveTo('/tmp/'.$attachment->filename());
}
```

| Method | Returns | Notes |
| --- | --- | --- |
| `attachments()` | `list<Attachment>` | **genuine** attachments only (no inline images) |
| `inlineAttachments()` | `list<Attachment>` | embedded body images (logos, icons, pixels) |
| `allAttachments()` | `list<Attachment>` | everything |
| `hasAttachments()` | `bool` | true only when there are genuine attachments |
| `inlineAttachmentByContentId($cid)` | `?Attachment` | resolve a `cid:` reference |

`contentType()` returns the type exactly as the message declared it (fast, and
`null` when omitted). `mimeType()` falls back to detecting the type from the
payload's **magic bytes** (via `ext-fileinfo`) — never from the file-name
extension, which is attacker-controlled and can lie. Use `mimeType()` when you
need a dependable type; note it resolves the content to sniff it.

#### Real attachments vs. inline/embedded images

A common headache with other libraries is that the four tiny social-media icons
in a signature, an embedded logo or a tracking pixel all show up as
"attachments", drowning out the one PDF the sender actually attached.

`attachments()` returns only the **genuine** ones. Each part is tagged with an
`AttachmentDisposition` (`Attachment` or `Inline`), decided from what the message
*actually does* rather than a single unreliable header:

1. **Is the part referenced by the body?** If the HTML contains `cid:<its-id>` (or
   its Content-Location), it is body content → **Inline**. This is the decisive
   signal, and it catches the notorious case where Outlook marks a
   body-referenced image as `Content-Disposition: attachment`.
2. **`Content-Disposition: inline`**, or the MAPI `ATT_MHTML_REF` / hidden flags
   for `.msg` → **Inline**.
3. **Sits in a `multipart/related` container** as an image → **Inline**.
4. Otherwise → a real **Attachment** (an unreferenced image with a stray
   Content-ID is still a file the sender attached).

```php
$email->attachments();       // the PDF the customer actually sent
$email->inlineAttachments(); // the LinkedIn/Twitter icons + logo + tracking pixel

$attachment->disposition();     // AttachmentDisposition::Attachment | ::Inline
$attachment->isEmbeddedImage(); // true for an inline image
```

### Nested / embedded messages

When an attachment is itself an email — a `.eml` attached to a `.eml`, a `.msg`
embedded in a `.msg`, or one format inside the other — it is parsed recursively
into its own `Email`:

```php
foreach ($email->attachments() as $attachment) {
    if ($attachment->isEmbeddedMessage()) {
        $inner = $attachment->embeddedMessage();   // a full Email instance
        $inner->subject();
        $inner->attachments();                      // …with its own attachments
    }
}

$email->embeddedMessages();         // list<Email> — just the nested messages
$email->allAttachmentsRecursive();  // every file across every nesting level
```

Recursion (and its depth limit) is configurable — see
[Nested message options](#nested-message-options). It is bounded by `max_depth`
to stay safe against maliciously deep files.

### Headers

```php
$email->header('Message-ID');     // first value, case-insensitive
$email->headers()->all('Received'); // every value of a repeated header
$email->headers()->toArray();       // name => first value
```

For `.msg` files the headers bag is populated from the original transport headers
(`PR_TRANSPORT_MESSAGE_HEADERS`) when present, and otherwise synthesised from the
MAPI properties so `Subject`, `From`, `To`, `Date` and `Message-ID` are always there.

### `Address`

```php
$from = $email->from();
$from->name;          // "Jane Doe"
$from->email;         // "jane@acme.test"
$from->displayName(); // name, or email if there is no name
(string) $from;       // '"Jane Doe" <jane@acme.test>'
```

### Serialising

`$email->toArray()` returns a plain array of everything (minus attachment bytes),
handy for logging, JSON responses or persisting metadata.

## Email threads (forwards & replies)

When a message forwards or replies to earlier ones, the chain can be
reconstructed — including the different contacts at each hop.

```php
$email = MailFile::read('fwd.eml');

$email->isForwarded();        // true
$email->isReply();            // false

// The original author + who it was originally addressed to:
$email->originalSender();     // Address { "Alice Original", alice@origin.example }
$email->originalRecipients(); // [Address bob@example.com]

// Who it was forwarded TO is simply the current recipient:
$email->to();                 // [Address carol@example.com]

// The full chain, newest-first, with per-message contacts:
foreach ($email->thread()->messages() as $msg) {
    $msg->type();     // ThreadEntryType::Message | Forwarded | Reply | Attached
    $msg->from();     // Address|null
    $msg->to();       // list<Address>
    $msg->cc();
    $msg->subject();
    $msg->date();
}
```

`Thread` helpers: `current()` (the message itself), `original()` (the oldest
recovered message), `hasHistory()`, `count()`, `isForwarded()`, `isReply()`.

**How the chain is recovered, in order of reliability:**

1. **Attached originals** — a message forwarded *as an attachment*
   (`message/rfc822` or an embedded `.msg`) is parsed structurally, so its
   contacts are exact (`ThreadEntryType::Attached`).
2. **Quoted "forwarded message" header blocks** in the body — the `From:`/`To:`/
   `Cc:`/`Subject:` lines are parsed, giving full per-item contacts
   (`ThreadEntryType::Forwarded`).
3. **Reply attribution lines** ("On … wrote:") — yield the quoted author
   (`ThreadEntryType::Reply`).
4. **Forwarding headers** — `X-Original-Sender`, `Resent-From`,
   `X-MS-Exchange-Organization-OriginalSender`, etc., as a fallback.

> Body-based recovery (2 & 3) is **heuristic**: it reads text a human wrote, so
> it copes with quirks like non-breaking spaces, Outlook HTML-table layouts and
> ~15 languages of labels/verbs — but it cannot be perfect. Prefer
> forwarded-as-attachment when accuracy is critical. `ThreadMessage::isReliable()`
> tells you whether an entry came from a structural source (1) or a heuristic one.

## Signed, encrypted & special parts

```php
$email->isSigned();       // multipart/signed (S/MIME or PGP)
$email->isEncrypted();    // multipart/encrypted or S/MIME enveloped
$email->securityProtocol();// "smime" | "pgp" | null
```

For signed messages the *signed content* is what you read through `body()` and
`attachments()` — the detached signature part is not surfaced as an attachment.

Calendar invitations (text/calendar) are parsed into events:

```php
if ($email->hasCalendar()) {
    $event = $email->calendarEvents()[0];
    $event->method();     // REQUEST | CANCEL | REPLY | ...
    $event->summary();
    $event->organizer();  // ?Address
    $event->attendees();  // list<Address>
    $event->start(); $event->end();
    $event->isCancellation();
}
```

Automated messages are flagged too:

```php
$email->isDeliveryStatusNotification(); // a bounce/DSN
$email->isReadReceipt();                // an MDN
$email->hasTnef();                      // carries winmail.dat
$email->tnefAttachment();               // the raw TNEF bytes, if any
```

## Mailboxes and batch reading

Read a whole Unix **mbox** spool (streaming — one message in memory at a time):

```php
foreach (MailFile::mbox('/var/mail/archive.mbox') as $email) {
    echo $email->subject().PHP_EOL;
}
```

Or every `.eml`/`.msg` in a **directory** (keyed by path):

```php
foreach (MailFile::directory('/inbox', recursive: true) as $path => $email) {
    // ...
}
```

## Memory & performance

- Attachment payloads and nested messages are **resolved lazily**: listing
  attachments (names, types, content-ids, disposition) does not decode or hold
  the bytes. Only the attachments you call `content()`, `size()` or `saveTo()`
  on are materialised — note that `size()` resolves the payload, because a MIME
  body's *decoded* length is not known from its headers. `.msg` streams are
  likewise read on demand.
- **Header-only reads** for indexing skip the body, attachments, calendar and
  nested messages entirely. For EML they read only the header block from disk
  (not the whole file):

  ```php
  $email = MailFile::headers('/inbox/msg-42.eml'); // or ::headers() on a .msg
  $email->isHeadersOnly();  // true
  $email->subject(); $email->from(); $email->date(); $email->inReplyTo();
  $email->body();           // null — reparse with MailFile::read() for the body
  ```

- `mbox` reading streams message-by-message.
- The remaining whole-file load is the source file for a *full* `read()`
  (`file_get_contents`), because MIME needs to scan boundaries and OLE2 needs
  random access. For a mailbox, prefer `MailFile::mbox()`; for indexing, prefer
  `MailFile::headers()`. A fully streaming single-message parser is not provided.

## Scope & boundaries

In scope: reading `.eml` (RFC 822/MIME) and `.msg` (Outlook MAPI), mbox spools,
and directories. Deliberately **out of scope**:

- **Writing/mutating** messages — this package is read-only.
- **PST/OST** mailbox databases — use a dedicated extractor to split them into
  `.msg`/`.eml` first, then read those here.
- **Decrypting** S/MIME/PGP or **verifying** signatures — the package *detects*
  them and reads the signed content, but does no cryptography.
- **Decoding the TNEF (winmail.dat) container** — it is detected and its bytes
  exposed, but not unpacked.

## Laravel usage

```php
use Melloww\MailFiles\MailFileReader;

class InboxController
{
    public function show(MailFileReader $reader, Request $request)
    {
        $email = $reader->readUploadedFile($request->file('mail'));

        return response()->json([
            'subject' => $email->subject(),
            'from'    => $email->from()?->toArray(),
            'to'      => array_map(fn ($a) => $a->toArray(), $email->to()),
            'body'    => $email->cleanHtml() ?? $email->body(),
        ]);
    }
}
```

### Artisan command

A small reference command prints a parsed summary of any file:

```bash
php artisan mailfiles:inspect storage/app/message.msg
```

## EML drivers

`.eml` parsing has two interchangeable drivers, configured in `config/mailfiles.php`:

```php
'eml' => [
    'driver' => env('MAILFILES_EML_DRIVER', 'native'), // native | mailparse | auto
],
```

- **`native`** (default) — the bundled pure-PHP MIME parser. No extension required.
- **`mailparse`** — delegates *structural* parsing to the PHP
  [`mailparse`](https://www.php.net/manual/en/book.mailparse.php) extension. Decoding,
  charset handling and the public API are identical to the native driver.
- **`auto`** — use `mailparse` when the extension is loaded, otherwise `native`.

`.msg` files are always read by the bundled native MAPI reader.

## Nested message options

Recursive extraction of messages-within-messages is on by default and tunable in
`config/mailfiles.php`:

```php
'attachments' => [
    'extract_nested' => env('MAILFILES_EXTRACT_NESTED', true), // parse embedded .eml/.msg
    'max_depth'      => env('MAILFILES_MAX_DEPTH', 5),          // how deep to recurse
],
```

Outside Laravel, pass a `ParseOptions` to the reader:

```php
use Melloww\MailFiles\MailFileReader;
use Melloww\MailFiles\ParseOptions;

$reader = new MailFileReader('native', new ParseOptions(
    extractNestedMessages: true,
    maxDepth: 3,
));
```

## How it works

- **`.eml`** — a recursive RFC 822 / MIME parser: header unfolding, RFC 2047
  encoded-words, RFC 2231 parameter continuations, nested `multipart/*` trees,
  quoted-printable / base64 / uuencode transfer decoding and charset conversion
  to UTF-8.
- **`.msg`** — a read-only OLE2 / Compound File reader (MS-CFB: FAT, mini-FAT and
  the directory tree) feeding a MAPI property decoder (MS-OXMSG / MS-OXPROPS) that
  understands the `__substg1.0_*` streams, the `__properties_version1.0` table, and
  the `__recip_*` / `__attach_*` storages.

## Replacing `php-mime-mail-parser` + `hfig/mapi`

| Previously | Now |
| --- | --- |
| `(new Parser)->setText($eml)->getSubject()` | `MailFile::read($path)->subject()` |
| `$parser->getHeader('from')` | `$email->from()` / `$email->header('from')` |
| `$parser->getTo()` | `$email->to()` |
| `$parser->getMessageBody('text')` | `$email->textBody()` |
| `$parser->getMessageBody('html')` | `$email->htmlBody()` |
| `$parser->getAttachments()` | `$email->attachments()` |
| `$messageFactory->parseMessage($doc)->getProperties()['subject']` | `$email->subject()` |
| `$msg->getAttachments()[0]->getContent()` | `$email->attachments()[0]->content()` |

## Testing

```bash
composer test       # Pest
composer analyse    # PHPStan
composer format     # Pint
```

The test suite runs against real `.eml` and `.msg` fixtures in `tests/Fixtures`.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
