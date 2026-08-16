<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An edited version of one of the platform's emails. A row here
 * overrides the code default; no row means the code default stands.
 *
 * Placeholders are written as {name} and replaced at send time from the
 * data the Mailable already has. Deliberately a simple brace syntax
 * rather than Blade: this content is edited by a person in a textarea
 * and stored in the database, so anything that could execute would be
 * a genuine remote code execution path.
 */
class MailTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'body', 'updated_by_user_id'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Fills {placeholders} from the given values. Unknown placeholders
     * are left exactly as typed rather than blanked, so a typo shows up
     * as "{schoolnam}" in a test send instead of silently vanishing and
     * leaving a sentence with a hole in it.
     */
    public static function fill(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            $text = str_replace('{'.$key.'}', (string) $value, $text);
        }

        return $text;
    }

    /**
     * The edited copy for one mail, or null to use the code default.
     * Values are substituted before returning.
     *
     * @return array{subject: string, body: string}|null
     */
    public static function resolve(string $key, array $values = []): ?array
    {
        $template = static::where('key', $key)->first();

        if (! $template) {
            return null;
        }

        return [
            'subject' => static::fill($template->subject, $values),
            // nl2br so an editor writing plain paragraphs in a textarea
            // gets paragraphs in the email, without needing to know HTML.
            'body' => nl2br(static::fill($template->body, $values)),
        ];
    }
}
