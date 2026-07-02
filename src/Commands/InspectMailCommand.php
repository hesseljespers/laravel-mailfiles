<?php

declare(strict_types=1);

namespace Melloww\MailFiles\Commands;

use Illuminate\Console\Command;
use Melloww\MailFiles\Address;
use Melloww\MailFiles\MailFileReader;
use Throwable;

/**
 * A small reference command demonstrating the unified reading API:
 *
 *     php artisan mailfiles:inspect storage/app/message.msg
 */
class InspectMailCommand extends Command
{
    public $signature = 'mailfiles:inspect {path : Path to a .eml or .msg file}';

    public $description = 'Read a .eml or .msg file and print its parsed contents.';

    public function handle(MailFileReader $reader): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        try {
            $email = $reader->read($path);
        } catch (Throwable $e) {
            $this->error('Could not read the mail file: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>Format</>', strtoupper($email->format()->value));
        $this->components->twoColumnDetail('<fg=gray>Subject</>', $email->subject() ?? '—');
        $this->components->twoColumnDetail('<fg=gray>From</>', $email->from()?->toString() ?? '—');
        $this->components->twoColumnDetail('<fg=gray>To</>', $this->addresses($email->to()));
        $this->components->twoColumnDetail('<fg=gray>Cc</>', $this->addresses($email->cc()));
        $this->components->twoColumnDetail('<fg=gray>Date</>', $email->date()?->format('Y-m-d H:i:s P') ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Message-ID</>', $email->messageId() ?? '—');
        $this->components->twoColumnDetail('<fg=gray>Has HTML body</>', $email->hasHtmlBody() ? 'yes' : 'no');
        $this->components->twoColumnDetail('<fg=gray>Attachments</>', (string) count($email->attachments()));
        $this->components->twoColumnDetail('<fg=gray>Inline / embedded</>', (string) count($email->inlineAttachments()));
        $this->components->twoColumnDetail('<fg=gray>Embedded messages</>', (string) count($email->embeddedMessages()));

        foreach ($email->attachments() as $attachment) {
            $this->components->twoColumnDetail(
                '  <fg=gray>•</> '.($attachment->filename() ?? 'unnamed'),
                ($attachment->mimeType() ?? 'application/octet-stream').', '.$attachment->humanSize()
            );
        }

        if (($body = $email->body()) !== null) {
            $this->newLine();
            $this->line('<fg=gray>Body preview:</>');
            $this->line(mb_substr($body, 0, 500));
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<Address>  $addresses
     */
    private function addresses(array $addresses): string
    {
        if ($addresses === []) {
            return '—';
        }

        return implode(', ', array_map(static fn (Address $a): string => $a->toString(), $addresses));
    }
}
