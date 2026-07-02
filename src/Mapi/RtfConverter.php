<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Mapi;

use Melloww\MailFiles\Support\Charset;

/**
 * Recovers usable content from a decompressed RTF body.
 *
 * Outlook stores the "best" body as RTF. When that RTF encapsulates HTML
 * (`\fromhtml`, per [MS-OXRTFEX]) the original HTML can be de-encapsulated;
 * otherwise the RTF is reduced to plain text. This is a pragmatic converter
 * aimed at message bodies, not a full RTF renderer.
 */
final class RtfConverter
{
    /** Destinations whose content is never part of the message body. */
    private const IGNORED_DESTINATIONS = [
        'fonttbl', 'colortbl', 'stylesheet', 'listtable', 'listoverridetable',
        'info', 'generator', 'mhtmltag', 'pntext', 'pntxta', 'pntxtb', 'pict',
        'object', 'themedata', 'colorschememapping', 'latentstyles', 'datastore',
        'rsidtbl', 'template', 'header', 'footer', 'fldinst',
    ];

    private string $output = '';

    /** Buffered raw bytes (in the source code page) from consecutive \'xx escapes. */
    private string $pending = '';

    private string $codepage = 'Windows-1252';

    private bool $htmlMode = false;

    /** @var array{htmlrtf: bool, ignore: bool, htmltag: bool, uc: int} */
    private array $frame = ['htmlrtf' => false, 'ignore' => false, 'htmltag' => false, 'uc' => 1];

    /** @var list<array{htmlrtf: bool, ignore: bool, htmltag: bool, uc: int}> */
    private array $stack = [];

    private int $skip = 0;

    private bool $pendingStar = false;

    public static function isEncapsulatedHtml(string $rtf): bool
    {
        return str_contains($rtf, '\\fromhtml');
    }

    /**
     * De-encapsulate HTML from a `\fromhtml` RTF body, or null if it does not
     * encapsulate HTML.
     */
    public static function toHtml(string $rtf, string $fallbackCharset = 'Windows-1252'): ?string
    {
        if (! self::isEncapsulatedHtml($rtf)) {
            return null;
        }

        $html = (new self)->render($rtf, $fallbackCharset, htmlMode: true);

        return trim($html) === '' ? null : $html;
    }

    /**
     * Reduce any RTF body to plain text.
     */
    public static function toText(string $rtf, string $fallbackCharset = 'Windows-1252'): string
    {
        return trim((new self)->render($rtf, $fallbackCharset, htmlMode: false));
    }

    private function render(string $rtf, string $fallbackCharset, bool $htmlMode): string
    {
        $this->output = '';
        $this->pending = '';
        $this->stack = [];
        $this->skip = 0;
        $this->pendingStar = false;
        $this->htmlMode = $htmlMode;
        $this->frame = ['htmlrtf' => false, 'ignore' => false, 'htmltag' => false, 'uc' => 1];
        $this->codepage = preg_match('/\\\\ansicpg(\d+)/', $rtf, $m) === 1
            ? Charset::fromCodepage((int) $m[1])
            : $fallbackCharset;

        $length = strlen($rtf);
        $pos = 0;

        while ($pos < $length) {
            $char = $rtf[$pos];

            if ($char === '{') {
                $this->flush();
                $this->stack[] = $this->frame;
                $pos++;

                continue;
            }

            if ($char === '}') {
                $this->flush();
                $this->frame = array_pop($this->stack) ?? $this->frame;
                $pos++;

                continue;
            }

            if ($char === '\\') {
                [$token, $pos] = self::readControl($rtf, $pos, $length);

                if ($token['type'] === 'symbol') {
                    if ($token['value'] === '*') {
                        $this->pendingStar = true;

                        continue;
                    }
                    $this->flush();
                    $this->emit(self::symbol($token['value']));

                    continue;
                }

                if ($token['type'] === 'hex') {
                    if ($this->skip > 0) {
                        $this->skip--;

                        continue;
                    }
                    $this->pending .= chr($token['param'] ?? 0);

                    continue;
                }

                // Control word: flush buffered code-page bytes first.
                $this->flush();
                $this->handleControlWord($token['word'], $token['param']);

                continue;
            }

            // Raw text run (CR/LF are not significant in RTF).
            $next = self::nextControl($rtf, $pos, $length);
            $text = str_replace(["\r", "\n"], '', substr($rtf, $pos, $next - $pos));
            $pos = $next;

            if ($text === '') {
                continue;
            }

            if ($this->skip > 0) {
                $consume = min($this->skip, strlen($text));
                $this->skip -= $consume;
                $text = substr($text, $consume);
                if ($text === '') {
                    continue;
                }
            }

            $this->flush();
            $this->emit($text);
        }

        $this->flush();

        return $this->output;
    }

    private function handleControlWord(string $word, ?int $param): void
    {
        if ($word === 'htmlrtf') {
            $this->frame['htmlrtf'] = $param !== 0;

            return;
        }

        if ($word === 'htmltag') {
            $this->frame['htmltag'] = $this->htmlMode;
            $this->frame['ignore'] = ! $this->htmlMode; // in text mode htmltag holds markup, skip it
            $this->pendingStar = false;

            return;
        }

        if ($this->pendingStar) {
            $this->pendingStar = false;
            $this->frame['ignore'] = true; // unknown \* destination

            return;
        }

        if (in_array($word, self::IGNORED_DESTINATIONS, true)) {
            $this->frame['ignore'] = true;

            return;
        }

        switch ($word) {
            case 'uc':
                $this->frame['uc'] = $param ?? 1;
                break;
            case 'u':
                $code = $param ?? 0;
                if ($code < 0) {
                    $code += 65536;
                }
                $this->emit(self::unicode($code));
                $this->skip = $this->frame['uc'];
                break;
            case 'par':
            case 'line':
                $this->emit($this->htmlMode ? '' : "\n");
                break;
            case 'tab':
                $this->emit($this->htmlMode ? '' : "\t");
                break;
            case 'lquote':
                $this->emit("\u{2018}");
                break;
            case 'rquote':
                $this->emit("\u{2019}");
                break;
            case 'ldblquote':
                $this->emit("\u{201C}");
                break;
            case 'rdblquote':
                $this->emit("\u{201D}");
                break;
            case 'endash':
                $this->emit("\u{2013}");
                break;
            case 'emdash':
                $this->emit("\u{2014}");
                break;
            case 'bullet':
                $this->emit("\u{2022}");
                break;
            default:
                // Any other control word carries no body text.
                break;
        }
    }

    private function emit(string $text): void
    {
        if ($this->frame['ignore']) {
            return;
        }

        if ($this->frame['htmltag'] || ! $this->frame['htmlrtf']) {
            $this->output .= $text;
        }
    }

    private function flush(): void
    {
        if ($this->pending !== '') {
            $this->emit(Charset::toUtf8($this->pending, $this->codepage));
            $this->pending = '';
        }
    }

    /**
     * @return array{0: array{type: string, word: string, param: int|null, value: string, ...}, 1: int}
     */
    private static function readControl(string $rtf, int $pos, int $length): array
    {
        $pos++; // consume backslash

        if ($pos >= $length) {
            return [['type' => 'symbol', 'word' => '', 'param' => null, 'value' => ''], $pos];
        }

        $char = $rtf[$pos];

        if (! ctype_alpha($char)) {
            if ($char === "'") {
                $value = (int) hexdec(substr($rtf, $pos + 1, 2));

                return [['type' => 'hex', 'word' => '', 'param' => $value, 'value' => ''], $pos + 3];
            }

            return [['type' => 'symbol', 'word' => '', 'param' => null, 'value' => $char], $pos + 1];
        }

        $start = $pos;
        while ($pos < $length && ctype_alpha($rtf[$pos])) {
            $pos++;
        }
        $word = substr($rtf, $start, $pos - $start);

        $param = null;
        if ($pos < $length && ($rtf[$pos] === '-' || ctype_digit($rtf[$pos]))) {
            $numStart = $pos;
            if ($rtf[$pos] === '-') {
                $pos++;
            }
            while ($pos < $length && ctype_digit($rtf[$pos])) {
                $pos++;
            }
            $param = (int) substr($rtf, $numStart, $pos - $numStart);
        }

        if ($pos < $length && $rtf[$pos] === ' ') {
            $pos++; // the delimiting space is part of the control word
        }

        return [['type' => 'word', 'word' => $word, 'param' => $param, 'value' => ''], $pos];
    }

    private static function nextControl(string $rtf, int $pos, int $length): int
    {
        for ($i = $pos; $i < $length; $i++) {
            $c = $rtf[$i];
            if ($c === '\\' || $c === '{' || $c === '}') {
                return $i;
            }
        }

        return $length;
    }

    private static function symbol(string $symbol): string
    {
        return match ($symbol) {
            '\\', '{', '}' => $symbol,
            '~' => "\u{00A0}",
            '_' => "\u{2011}",
            default => '', // \- (optional hyphen) and others: nothing
        };
    }

    private static function unicode(int $code): string
    {
        $char = mb_chr($code, 'UTF-8');

        return $char === false ? '' : $char;
    }
}
