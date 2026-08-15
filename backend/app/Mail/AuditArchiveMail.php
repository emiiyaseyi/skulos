<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Monthly audit trail archive notice (2026-08-15). Sends a download
 * LINK by default rather than the CSV itself — see
 * config('skulos.audit_archive.attach_csv') for the reasoning. When a
 * school has deliberately opted into attachments, the file is still
 * only attached if it fits under the recipient-side size cap.
 */
class AuditArchiveMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected string $schoolName,
        protected string $periodLabel,
        protected int $rows,
        protected int $bytes,
        protected int $retentionDays,
        protected string $downloadUrl,
        protected ?string $attachPath = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->schoolName} audit trail for {$this->periodLabel}");
    }

    public function attachments(): array
    {
        if (! $this->attachPath) {
            return [];
        }

        return [
            \Illuminate\Mail\Mailables\Attachment::fromStorageDisk('local', $this->attachPath)
                ->as(str_replace(' ', '-', $this->periodLabel).'-audit-trail.csv')
                ->withMime('text/csv'),
        ];
    }

    public function content(): Content
    {
        $size = \App\Services\Maintenance\StorageService::humanBytes($this->bytes);
        $rows = number_format($this->rows);

        $delivery = $this->attachPath
            ? '<p style="margin:0 0 16px;">This month\'s audit trail is attached as a CSV file, and can also be downloaded from your school portal.</p>'
            : '<p style="margin:0 0 16px;">This month\'s audit trail is ready to download from your school portal. It is not attached to this email on purpose: an audit trail records who viewed which records, so it stays behind your own login rather than sitting in an inbox.</p>';

        $body = "<p style=\"margin:0 0 16px;\">The audit trail for <strong>{$this->periodLabel}</strong> has been archived: {$rows} entries, {$size}.</p>"
            .$delivery
            .'<p style="margin:0 0 20px;"><a href="'.e($this->downloadUrl).'" style="background:#C88A2E;color:#241503;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:bold;">Download the audit trail</a></p>'
            ."<p style=\"margin:0 0 16px;color:#7C8698;font-size:13px;\">Entries older than {$this->retentionDays} days are removed from the live system after being archived, to keep your school's storage manageable.</p>"
            .'<p style="margin:0;color:#7C8698;font-size:13px;"><strong>Important:</strong> your school is responsible for downloading, storing and tracking its own audit records for as long as it needs them, including for any regulatory or legal requirement that applies to your school. SKULOS provides this archive and download as a convenience and cannot be held liable for audit records a school has not retained itself.</p>';

        $text = "Audit trail for {$this->periodLabel}: {$rows} entries, {$size}.\n\n"
            ."Download: {$this->downloadUrl}\n\n"
            ."Entries older than {$this->retentionDays} days are removed from the live system after archiving.\n\n"
            .'IMPORTANT: your school is responsible for downloading, storing and tracking its own audit records for as long as it needs them, including for any regulatory or legal requirement that applies. SKULOS provides this archive as a convenience and cannot be held liable for audit records a school has not retained itself.';

        return new Content(
            view: 'emails.layout',
            text: 'emails.plain',
            with: [
                'subject' => "{$this->schoolName} audit trail for {$this->periodLabel}",
                'body' => $body,
                'text' => $text,
            ],
        );
    }
}
