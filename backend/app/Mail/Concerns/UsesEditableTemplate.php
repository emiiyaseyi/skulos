<?php

namespace App\Mail\Concerns;

use App\Models\MailTemplate;

/**
 * Lets a Mailable's wording be edited from Settings > Mail Templates
 * (2026-08-16, direct instruction: "there should be a page to edit all
 * the formats of mails going out and use the dynamic function in the
 * body of the mail as no mail is static").
 *
 * What an edit replaces is the PROSE of the message, not the whole
 * email. The sign in details box, the demo account table and the call
 * to action button are still built by the Mailable and appended
 * afterwards. Two reasons: a temporary password can never be lost by
 * someone editing a paragraph and deleting the placeholder that carried
 * it, and the layout the owner asked to keep stays intact no matter what
 * anyone writes.
 *
 * With no saved template the Mailable's own wording is used unchanged,
 * so nothing here has to be filled in for mail to keep working.
 */
trait UsesEditableTemplate
{
    /** @var array{subject: string, body: string}|null */
    protected ?array $resolvedTemplate = null;

    protected bool $templateWasResolved = false;

    /**
     * The real values behind this mail's placeholders. Anything not
     * listed here stays visible as {placeholder} rather than silently
     * disappearing, so a typo shows up instead of leaving a hole in a
     * sentence.
     */
    abstract protected function templateValues(): array;

    /** @return array{subject: string, body: string}|null */
    protected function template(): ?array
    {
        // envelope() and content() are both called per send, so resolve
        // once and reuse rather than querying twice for one message.
        if (! $this->templateWasResolved) {
            $this->templateWasResolved = true;
            $this->resolvedTemplate = MailTemplate::resolve(class_basename($this), $this->templateValues());
        }

        return $this->resolvedTemplate;
    }

    protected function templateSubject(string $default): string
    {
        return $this->template()['subject'] ?? $default;
    }

    /** The edited prose as HTML, or the Mailable's own if none is saved. */
    protected function templateBody(string $default): string
    {
        $body = $this->template()['body'] ?? null;

        return $body === null ? $default : '<div style="line-height:22px;">'.$body.'</div>';
    }

    /** The same prose as plain text, for the text half of the message. */
    protected function templateText(string $default): string
    {
        $body = $this->template()['body'] ?? null;

        return $body === null
            ? $default
            : trim(html_entity_decode(strip_tags(str_replace(['<br />', '<br>', '<br/>'], "\n", $body)), ENT_QUOTES));
    }
}
