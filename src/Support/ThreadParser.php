<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Support;

use Melloww\MailFiles\Address;
use Melloww\MailFiles\Email;
use Melloww\MailFiles\Enums\ThreadEntryType;
use Melloww\MailFiles\ThreadMessage;

/**
 * Best-effort recovery of quoted messages from an email body.
 *
 * Recognises two families of quoting:
 *   - "forwarded message" header blocks (From/To/Cc/Date/Subject lines), which
 *     carry full contact information;
 *   - reply attribution lines ("On … wrote:"), which carry the author.
 *
 * The keyword lists are deliberately multilingual. Detection is heuristic by
 * nature — the structured data from {@see Email::embeddedMessages()}
 * should be preferred when a message was forwarded as an attachment.
 */
final class ThreadParser
{
    /** Header label (lower-cased) => canonical field. */
    private const LABELS = [
        // From
        'from' => 'from', 'van' => 'from', 'von' => 'from', 'de' => 'from', 'da' => 'from',
        'fra' => 'from', 'från' => 'from', 'lähettäjä' => 'from', 'od' => 'from', 'feladó' => 'from',
        'de la' => 'from', 'от' => 'from', 'від' => 'from', 'από' => 'from', 'kimden' => 'from',
        'من' => 'from', 'מאת' => 'from', '发件人' => 'from', '差出人' => 'from', '보낸 사람' => 'from',
        // To
        'to' => 'to', 'aan' => 'to', 'an' => 'to', 'à' => 'to', 'a' => 'to', 'para' => 'to',
        'til' => 'to', 'till' => 'to', 'vastaanottaja' => 'to', 'do' => 'to', 'címzett' => 'to',
        'către' => 'to', 'кому' => 'to', 'до' => 'to', 'προς' => 'to', 'kime' => 'to',
        'إلى' => 'to', 'אל' => 'to', '收件人' => 'to', '宛先' => 'to', '받는 사람' => 'to',
        // Cc
        'cc' => 'cc', 'kopie' => 'cc', 'copie' => 'cc', 'copia' => 'cc', 'cópia' => 'cc',
        'kopia' => 'cc', 'копия' => 'cc', '抄送' => 'cc', 'kopya' => 'cc',
        // Date / Sent
        'sent' => 'date', 'date' => 'date', 'verzonden' => 'date', 'gesendet' => 'date',
        'datum' => 'date', 'envoyé' => 'date', 'enviado' => 'date', 'inviato' => 'date',
        'data' => 'date', 'sendt' => 'date', 'skickat' => 'date', 'lähetetty' => 'date',
        'dato' => 'date', 'wysłano' => 'date', 'dátum' => 'date', 'gönderildi' => 'date',
        'отправлено' => 'date', '发送时间' => 'date', '送信日時' => 'date', '보낸 날짜' => 'date',
        // Subject
        'subject' => 'subject', 'onderwerp' => 'subject', 'betreff' => 'subject', 'objet' => 'subject',
        'asunto' => 'subject', 'oggetto' => 'subject', 'assunto' => 'subject', 'ämne' => 'subject',
        'aihe' => 'subject', 'emne' => 'subject', 'temat' => 'subject', 'tárgy' => 'subject',
        'konu' => 'subject', 'θέμα' => 'subject', 'тема' => 'subject', 'sujet' => 'subject',
        '主题' => 'subject', '件名' => 'subject', '제목' => 'subject',
    ];

    /** Verbs that terminate a reply attribution line, per language. */
    private const REPLY_VERBS = [
        'wrote', 'schreef', 'schrieb', 'a écrit', 'escribió', 'ha scritto', 'escreveu',
        'napisał', 'skrev', 'kirjoitti', 'napsal', 'írta',
    ];

    /**
     * @return list<ThreadMessage>
     */
    public static function parse(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        // Outlook peppers headers with non-breaking / narrow spaces that PCRE's
        // \s does not match; fold them to plain spaces first.
        $body = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $body);

        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $entries = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (self::isReplyAttribution($line)) {
                $entries[] = self::replyFrom($line);

                continue;
            }

            $block = self::readHeaderBlock($lines, $i, $count);

            if ($block !== null) {
                [$message, $next] = $block;
                $entries[] = $message;
                $i = $next;
            }
        }

        return self::dedupe($entries);
    }

    /**
     * Try to read a run of header lines (From/To/Cc/Date/Subject) starting at $start.
     *
     * @param  list<string>  $lines
     * @return array{0: ThreadMessage, 1: int}|null the message and the index of its last line
     */
    private static function readHeaderBlock(array $lines, int $start, int $count): ?array
    {
        $fields = [];
        $last = $start - 1;

        for ($i = $start; $i < $count && $i < $start + 12; $i++) {
            $parsed = self::matchLabel($lines[$i]);

            if ($parsed === null) {
                break;
            }

            [$field, $value] = $parsed;

            // First value wins for single-valued fields; recipients accumulate.
            if (in_array($field, ['to', 'cc'], true) && isset($fields[$field])) {
                $fields[$field] .= ', '.$value;
            } else {
                $fields[$field] ??= $value;
            }

            $last = $i;
        }

        // Require a From plus at least one more field to treat it as a real block.
        if (! isset($fields['from']) || count($fields) < 2) {
            return null;
        }

        $message = new ThreadMessage(
            type: ThreadEntryType::Forwarded,
            from: AddressParser::parseList($fields['from'])[0] ?? null,
            to: isset($fields['to']) ? AddressParser::parseList($fields['to']) : [],
            cc: isset($fields['cc']) ? AddressParser::parseList($fields['cc']) : [],
            date: isset($fields['date']) ? DateParser::parse($fields['date']) : null,
            subject: isset($fields['subject']) ? MimeHeaderDecoder::decode(trim($fields['subject'])) : null,
        );

        return [$message, $last];
    }

    /**
     * @return array{0: string, 1: string}|null [field, value]
     */
    private static function matchLabel(string $line): ?array
    {
        if (preg_match('/^\s*([\p{L}][\p{L} ]{0,20}?)\s*:\s*(.+?)\s*$/u', $line, $m) !== 1) {
            return null;
        }

        $label = mb_strtolower(trim($m[1]));
        $field = self::LABELS[$label] ?? null;

        if ($field === null) {
            return null;
        }

        return [$field, $m[2]];
    }

    private static function isReplyAttribution(string $line): bool
    {
        $line = trim($line);

        // An attribution ends with a colon, mentions an address, and contains a
        // "wrote"-type verb. The verb position varies by language (English puts
        // it last, Dutch/German put it before the name), so we only require its
        // presence anywhere on the line.
        if (! str_ends_with($line, ':') || preg_match('/[<@]/', $line) !== 1) {
            return false;
        }

        $verbs = implode('|', array_map(static fn (string $v) => preg_quote($v, '/'), self::REPLY_VERBS));

        return preg_match('/(?:^|\P{L})(?:'.$verbs.')(?:\P{L}|$)/u', $line) === 1;
    }

    private static function replyFrom(string $line): ThreadMessage
    {
        // The email address is the only part we can extract reliably across
        // languages; display names sit on different sides of the verb.
        $email = null;

        if (preg_match('/<([^>\s@]+@[^>\s]+)>/', $line, $m) === 1) {
            $email = $m[1];
        } elseif (preg_match('/([\w.+-]+@[\w.-]+\.\w+)/', $line, $m) === 1) {
            $email = $m[1];
        }

        // Reject fragments that merely look like an address (e.g. an HTML tag).
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = null;
        }

        return new ThreadMessage(
            type: ThreadEntryType::Reply,
            from: $email !== null ? new Address($email) : null,
        );
    }

    /**
     * Drop adjacent entries that describe the same author (a reply attribution
     * followed by the matching forwarded block, for example).
     *
     * @param  list<ThreadMessage>  $entries
     * @return list<ThreadMessage>
     */
    private static function dedupe(array $entries): array
    {
        $result = [];
        $previousEmail = null;

        foreach ($entries as $entry) {
            $email = $entry->from()?->email();

            if ($email !== null && $email === $previousEmail) {
                // Keep the richer of the two (the one with recipients).
                $last = count($result) - 1;
                if ($last >= 0 && $result[$last]->to() === [] && $entry->to() !== []) {
                    $result[$last] = $entry;
                }

                continue;
            }

            $result[] = $entry;
            $previousEmail = $email;
        }

        return $result;
    }
}
