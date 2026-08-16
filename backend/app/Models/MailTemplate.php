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
 *
 * The body supports a small, deliberately limited markup (2026-08-16,
 * found necessary the first time an admin actually compared an edited
 * template against the real trial approval letter, which has section
 * headings and bullet lists the plain "one blank line = one paragraph"
 * rule cannot represent):
 *   - a line starting with "## " becomes a bold section heading
 *   - a line starting with "- " becomes a bullet list item (consecutive
 *     "- " lines group into one list)
 *   - anything else is plain paragraph text, wrapped at blank lines
 * No raw HTML is ever accepted — every piece of text is escaped, so the
 * remote-code-execution concern above still holds; this only adds
 * structure on top of it.
 */
class MailTemplate extends Model
{
    protected $fillable = ['key', 'subject', 'body', 'updated_by_user_id'];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Replaces {placeholders} with the given values. Named substitute()
     * rather than the more natural fill(), because Eloquent already
     * defines fill() as an instance method and PHP will not let a model
     * redeclare it as a static one.
     *
     * Unknown placeholders
     * are left exactly as typed rather than blanked, so a typo shows up
     * as "{schoolnam}" in a test send instead of silently vanishing and
     * leaving a sentence with a hole in it.
     */
    public static function substitute(string $text, array $values): string
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
     * @return array{subject: string, body: string, text: string}|null
     */
    public static function resolve(string $key, array $values = []): ?array
    {
        $template = static::where('key', $key)->first();

        if (! $template) {
            return null;
        }

        $rawBody = static::substitute($template->body, $values);

        return [
            'subject' => static::substitute($template->subject, $values),
            'body' => static::renderBody($rawBody),
            // Built from the raw markup, not by stripping tags out of
            // the rendered HTML: a bullet list's <li> tags carry no line
            // break of their own, so stripping them afterwards squashes
            // every item onto one unreadable line in the text part.
            'text' => static::plainTextBody($rawBody),
        ];
    }

    /** Turns the {##/-} markup described above into safe, styled HTML. */
    public static function renderBody(string $raw): string
    {
        $headingStyle = 'margin:22px 0 8px;font-size:15px;font-weight:bold;color:#0B1420;';
        $paragraphStyle = 'margin:0 0 14px;line-height:22px;';
        $listItemStyle = 'margin:0 0 4px;line-height:20px;';

        $html = '';
        $paragraph = [];
        $list = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                [$html, $paragraph] = static::flushParagraph($html, $paragraph, $paragraphStyle);
                [$html, $list] = static::flushList($html, $list, $listItemStyle);

                continue;
            }

            if (str_starts_with($trimmed, '## ')) {
                [$html, $paragraph] = static::flushParagraph($html, $paragraph, $paragraphStyle);
                [$html, $list] = static::flushList($html, $list, $listItemStyle);
                $html .= '<p style="'.$headingStyle.'">'.e(substr($trimmed, 3)).'</p>';

                continue;
            }

            if (str_starts_with($trimmed, '- ')) {
                [$html, $paragraph] = static::flushParagraph($html, $paragraph, $paragraphStyle);
                $list[] = substr($trimmed, 2);

                continue;
            }

            [$html, $list] = static::flushList($html, $list, $listItemStyle);
            $paragraph[] = $trimmed;
        }

        [$html] = static::flushParagraph($html, $paragraph, $paragraphStyle);
        [$html] = static::flushList($html, $list, $listItemStyle);

        return $html;
    }

    /** @return array{0: string, 1: array<int, string>} */
    protected static function flushParagraph(string $html, array $paragraph, string $style): array
    {
        if ($paragraph === []) {
            return [$html, $paragraph];
        }

        $html .= '<p style="'.$style.'">'.implode('<br />', array_map('e', $paragraph)).'</p>';

        return [$html, []];
    }

    /** @return array{0: string, 1: array<int, string>} */
    protected static function flushList(string $html, array $list, string $itemStyle): array
    {
        if ($list === []) {
            return [$html, $list];
        }

        $items = implode('', array_map(fn ($item) => '<li style="'.$itemStyle.'">'.e($item).'</li>', $list));
        $html .= '<ul style="margin:0 0 14px;padding-left:20px;">'.$items.'</ul>';

        return [$html, []];
    }

    /** The same markup as plain text: prefixes dropped, structure kept. */
    public static function plainTextBody(string $raw): string
    {
        $lines = array_map(function ($line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '## ')) {
                return strtoupper(substr($trimmed, 3));
            }

            if (str_starts_with($trimmed, '- ')) {
                return '- '.substr($trimmed, 2);
            }

            return $trimmed;
        }, preg_split('/\r\n|\r|\n/', trim($raw)));

        return trim(implode("\n", $lines));
    }
}
