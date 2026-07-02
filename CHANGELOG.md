# Changelog

All notable changes to `laravel-mailfiles` will be documented in this file.

## 1.0.0 - Unreleased

Initial release.

- Unified, read-only API (`Email` value object) for both `.eml` (RFC 822 / MIME)
  and `.msg` (Outlook MAPI) files.
- From-scratch, pure-PHP parsers — no `php-mime-mail-parser` / `hfig/mapi`
  dependencies and no required PHP extension beyond `mbstring`.
  - Native OLE2 / Compound File reader + MAPI property decoder for `.msg`.
  - Native MIME parser for `.eml`, with an optional `mailparse`-extension driver.
- Automatic format detection from file contents.
- Subject, from/sender, to/cc/bcc/reply-to, date, message-id, text & HTML bodies,
  headers and attachments (including inline/`cid:` parts).
- Body accessors in HTML, plain text or Markdown (`markdown()` / `bodyAs()`),
  with a built-in dependency-free HTML→Markdown converter.
- Recursive extraction of nested messages (`.eml`-in-`.eml`, `.msg`-in-`.msg`,
  and cross-format) via `Attachment::embeddedMessage()`, with a configurable
  `max_depth`.
- Attachment classification: genuine attachments are separated from inline/
  embedded body images (signature logos, social icons, tracking pixels) via an
  `AttachmentDisposition` derived from body `cid:` references, disposition,
  `multipart/related` structure and MAPI flags — so `attachments()` returns only
  the files a user actually attached.
- Thread / conversation reconstruction: `Email::thread()` returns the chain of
  forwarded/replied messages (per-item from/to/cc/subject/date), plus
  `isForwarded()`, `isReply()`, `originalSender()` and `originalRecipients()`.
  Recovery combines attached originals, multilingual quoted forward/reply blocks
  (~15 languages, with non-breaking-space and Outlook HTML-table handling) and
  forwarding headers.
- Laravel integration: `MailFiles` facade, container binding, configurable EML
  driver and nested-extraction options, and a `mailfiles:inspect` Artisan command.
- Lazy attachment payloads and nested messages (closure-backed), and on-demand
  MAPI stream reads, so listing attachments never materialises their bytes.
- Rich metadata helpers: `inReplyTo()`, `references()`, `priority()`,
  `sensitivity()`, `isAutoSubmitted()`, `returnPath()`, `deliveredTo()`,
  `listId()`, `listUnsubscribe()`, read-receipt requests, and SPF/DKIM/DMARC
  results (from headers, and from MAPI properties for `.msg`).
- MIME-correct body selection (multipart/alternative richest-representation,
  nested alternative/related, multipart/mixed).
- Compressed-RTF (`PR_RTF_COMPRESSED`) decompression with HTML de-encapsulation
  / text fallback, exposed via `rtfBody()`.
- Special parts: `isSigned()`/`isEncrypted()` (S/MIME + PGP) with signed-content
  unwrapping, calendar invitations (`calendarEvents()`), delivery-status and
  disposition notifications, and TNEF (winmail.dat) detection.
- Batch reading: streaming `MailFile::mbox()` for Unix mbox spools and
  `MailFile::directory()` for folders of `.eml`/`.msg`.
- `Attachment::mimeType()`: falls back to content-based type detection (libmagic
  via ext-fileinfo, never the file-name extension) when the message declares no
  Content-Type. `contentType()` continues to return the declared value verbatim.
- Header-only reads: `MailFile::headers()` / `MailFileReader::readHeaders()`
  parse just the envelope + metadata (skipping body, attachments, calendar and
  nested messages) for cheap inbox indexing — EML reads only the header block
  from disk. `Email::isHeadersOnly()` reports the mode.
