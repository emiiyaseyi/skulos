<?php

namespace App\Listeners;

use App\Models\MailLog;
use App\Services\Tenancy\TenantManager;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Records every outgoing email by listening to Laravel's own mail
 * events (2026-08-16), rather than asking each Mailable to log itself.
 * That matters: an email added six months from now is captured with no
 * extra work, and nothing can quietly go unlogged because someone
 * forgot a line.
 *
 * Two events, on purpose. MessageSending writes the row before the send
 * is attempted, so a message that dies mid-transport still leaves a
 * trace instead of vanishing; MessageSent then marks it delivered.
 * Laravel emits no "failed" event, so a row still sitting at "sending"
 * well after the fact is what a failure looks like — MailLog reports
 * that as failed rather than pretending it is still in flight.
 */
class RecordSentMail
{
    public function sending(MessageSending $event): void
    {
        try {
            $log = MailLog::create([
                'recipient' => $this->recipients($event->message->getTo()),
                'subject' => $event->message->getSubject(),
                'mailable' => $event->data['__laravel_mailable'] ?? null,
                'status' => MailLog::STATUS_SENDING,
                'school_id' => app(TenantManager::class)->current()?->id,
            ]);

            // Carried on the message itself so the paired MessageSent
            // event can find this exact row again. Matching on recipient
            // and subject instead would pick the wrong row whenever the
            // same message goes to the same person twice.
            $event->message->getHeaders()->addTextHeader('X-Skulos-Log-Id', (string) $log->id);
        } catch (\Throwable $e) {
            // Logging an email must never be the reason the email fails
            // to go out.
            Log::warning('Could not record outgoing mail: '.$e->getMessage());
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            // Depending on the Laravel version this event carries either
            // the Symfony Email itself or a SentMessage wrapping it, so
            // unwrap only when there is something to unwrap. Getting
            // this wrong fails silently into the catch below and leaves
            // every row stuck at "sending".
            $message = method_exists($event->message, 'getOriginalMessage')
                ? $event->message->getOriginalMessage()
                : $event->message;

            $header = $message->getHeaders()->get('X-Skulos-Log-Id');
            if (! $header) {
                return;
            }

            // getBody(), not getBodyAsString(): the latter returns the
            // header's encoded form, which does not cast back to the id.
            $id = (int) (method_exists($header, 'getBody') ? $header->getBody() : $header->getBodyAsString());
            if ($id <= 0) {
                return;
            }

            MailLog::where('id', $id)->update([
                'status' => MailLog::STATUS_SENT,
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not mark outgoing mail as sent: '.$e->getMessage());
        }
    }

    /** @param  array<string, string>|iterable  $addresses */
    protected function recipients(iterable $addresses): string
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[] = is_object($address) && method_exists($address, 'getAddress')
                ? $address->getAddress()
                : (string) $address;
        }

        return mb_substr(implode(', ', $out) ?: 'unknown', 0, 250);
    }
}
